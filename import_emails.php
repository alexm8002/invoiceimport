<?php

require_once $_SERVER["DOCUMENT_ROOT"] . "/main.inc.php";
require_once "./class/invoiceimport.class.php";

global $conf, $db, $user;

// -------------------------
// CONFIG (depuis setup.php)
// -------------------------

$host = $conf->global->INVOICEIMPORT_IMAP_HOST;
$port = $conf->global->INVOICEIMPORT_IMAP_PORT ?: 993;
$userimap = $conf->global->INVOICEIMPORT_IMAP_USER;
$passimap = $conf->global->INVOICEIMPORT_IMAP_PASS;
$mailbox = $conf->global->INVOICEIMPORT_IMAP_MAILBOX ?: "INBOX";
$pdf_dir =
    $conf->global->INVOICEIMPORT_PDF_DIR ?: DOL_DATA_ROOT . "/invoiceimport";
$python = $conf->global->INVOICEIMPORT_PYTHON_BIN;

// -------------------------
// Sécurité
// -------------------------

if (empty($host) || empty($userimap) || empty($passimap)) {
    dol_syslog("IMAP config manquante", LOG_ERR);
    exit();
}

// -------------------------
// Connexion IMAP
// -------------------------

$mbox = "{" . $host . ":" . $port . "/imap/ssl}" . $mailbox;

$inbox = imap_open($mbox, $userimap, $passimap);

if (!$inbox) {
    dol_syslog("IMAP ERROR: " . imap_last_error(), LOG_ERR);
    exit();
}

dol_syslog("IMAP CONNECT OK", LOG_DEBUG);

// -------------------------
// Emails NON LUS
// -------------------------

$emails = imap_search($inbox, "UNSEEN");

if (!$emails) {
    dol_syslog("Aucun email non lu", LOG_DEBUG);
    imap_close($inbox);
    exit();
}

// -------------------------
// Traitement
// -------------------------

foreach ($emails as $email_number) {
    dol_syslog("EMAIL ID=" . $email_number, LOG_DEBUG);

    $structure = imap_fetchstructure($inbox, $email_number);

    if (!isset($structure->parts)) {
        continue;
    }

    foreach ($structure->parts as $i => $part) {
        if ($part->ifdparameters) {
            foreach ($part->dparameters as $object) {
                if (strtolower($object->attribute) == "filename") {
                    $filename = $object->value;

                    // uniquement PDF
                    if (!preg_match('/\.pdf$/i', $filename)) {
                        continue;
                    }

                    dol_syslog("PDF trouvé: " . $filename, LOG_DEBUG);

                    $body = imap_fetchbody($inbox, $email_number, $i + 1);

                    if ($part->encoding == 3) {
                        $body = base64_decode($body);
                    } elseif ($part->encoding == 4) {
                        $body = quoted_printable_decode($body);
                    }

                    // -------------------------
                    // Sauvegarde fichier
                    // -------------------------

                    if (!is_dir($pdf_dir)) {
                        dol_mkdir($pdf_dir);
                    }

                    $filepath = $pdf_dir . "/" . time() . "_" . $filename;

                    file_put_contents($filepath, $body);

                    dol_syslog("PDF sauvegardé: " . $filepath, LOG_DEBUG);

                    // -------------------------
                    // PARSE PYTHON
                    // -------------------------

                    $cmd =
                        $python .
                        " " .
                        DOL_DOCUMENT_ROOT .
                        "/custom/invoiceimport/python/parse_invoice.py " .
                        escapeshellarg($filepath) .
                        " 2>&1";

                    exec($cmd, $output);

                    $json = trim(implode("\n", $output));
                    $data = json_decode($json, true);

                    if (!$data) {
                        dol_syslog("JSON invalide", LOG_ERR);
                        continue;
                    }

                    // -------------------------
                    // IMPORT DOLIBARR
                    // -------------------------

                    $import = new InvoiceImport($db);
                    $id = $import->create($filename, $json);

                    if ($id > 0) {
                        $import->fetch($id);

                        $facture_id = $import->createSupplierInvoice(
                            $user,
                            $filepath,
                            $filename,
                            0,
                        );

                        dol_syslog("FACTURE ID=" . $facture_id, LOG_DEBUG);
                    }
                }
            }
        }
    }

    // -------------------------
    // Marquer comme lu
    // -------------------------
    imap_setflag_full($inbox, $email_number, "\\Seen");

    dol_syslog("EMAIL marqué comme lu", LOG_DEBUG);
}

imap_close($inbox);

dol_syslog("FIN import emails", LOG_DEBUG);
