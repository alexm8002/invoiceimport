<?php

require_once DOL_DOCUMENT_ROOT . "/core/class/commonobject.class.php";
require_once DOL_DOCUMENT_ROOT . "/core/lib/files.lib.php";
require_once DOL_DOCUMENT_ROOT .
    "/custom/invoiceimport/class/invoiceimport.class.php";

class EmailImport extends CommonObject
{
    public $element = "emailimport";
    public $table_element = "emailimport";

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function doScheduledJob($task = null, $cronjob = null)
    {
        global $user;

        dol_syslog("EmailImport CRON START", LOG_DEBUG);

        $this->processEmails($user);

        dol_syslog("EmailImport CRON END", LOG_DEBUG);

        return 0;
    }

    /**
     * Traite les emails non lus de la boîte IMAP configurée
     */
    public function processEmails($user)
    {
        $lockfile = DOL_DATA_ROOT . "/invoiceimport.lock";

        if (file_exists($lockfile)) {
            dol_syslog("EmailImport: CRON déjà en cours", LOG_WARNING);
            return;
        }

        file_put_contents($lockfile, time());

        try {
            // =========================
            // CONFIG
            // =========================
            $host = getDolGlobalString("INVOICEIMPORT_IMAP_HOST");
            $port = getDolGlobalString("INVOICEIMPORT_IMAP_PORT", "993");
            $userimap = getDolGlobalString("INVOICEIMPORT_IMAP_USER");
            $passimap = getDolGlobalString("INVOICEIMPORT_IMAP_PASS");
            $mailbox = getDolGlobalString(
                "INVOICEIMPORT_IMAP_MAILBOX",
                "INBOX",
            );
            $pdf_dir = getDolGlobalString(
                "INVOICEIMPORT_PDF_DIR",
                DOL_DATA_ROOT . "/invoiceimport",
            );
            $python = getDolGlobalString("INVOICEIMPORT_PYTHON_BIN");

            if (empty($python)) {
                $python =
                    DOL_DOCUMENT_ROOT . "/custom/invoiceimport/venv/bin/python";
            }

            if (empty($host) || empty($userimap) || empty($passimap)) {
                dol_syslog("EmailImport: config IMAP incomplète", LOG_ERR);
                return -1;
            }

            dol_mkdir($pdf_dir);

            if (!is_writable($pdf_dir)) {
                dol_syslog(
                    "EmailImport: dossier non writable " . $pdf_dir,
                    LOG_ERR,
                );
                return -2;
            }

            // =========================
            // IMAP
            // =========================
            $mbox =
                "{" .
                $host .
                ":" .
                $port .
                "/imap/ssl/novalidate-cert}" .
                $mailbox;

            $inbox = @imap_open($mbox, $userimap, $passimap);

            if (!$inbox) {
                dol_syslog(
                    "EmailImport: IMAP error " . imap_last_error(),
                    LOG_ERR,
                );
                return -3;
            }

            $emails = imap_search($inbox, "UNSEEN");

            if (!$emails) {
                imap_close($inbox);
                return 0;
            }

            $processed = 0;

            foreach ($emails as $email_number) {
                $structure = imap_fetchstructure($inbox, $email_number);

                if (!$structure) {
                    continue;
                }

                $attachments = $this->extractPdfAttachments(
                    $inbox,
                    $email_number,
                    $structure,
                );

                if (empty($attachments)) {
                    imap_setflag_full($inbox, $email_number, "\\Seen");
                    continue;
                }

                $email_ok = true;

                foreach ($attachments as $attachment) {
                    $filename = $attachment["filename"];
                    $content = $attachment["content"];

                    $filepath =
                        $pdf_dir .
                        "/email_" .
                        time() .
                        "_" .
                        mt_rand(1000, 9999) .
                        "_" .
                        dol_sanitizeFileName($filename);

                    // 1. écriture
                    if (file_put_contents($filepath, $content) === false) {
                        $email_ok = false;
                        continue;
                    }

                    // 2. hash APRES écriture
                    $hash = md5_file($filepath);

                    // 3. anti-doublon
                    $sql =
                        "SELECT rowid FROM " .
                        MAIN_DB_PREFIX .
                        "invoiceimport WHERE filename = '" .
                        $this->db->escape($hash) .
                        "'";
                    $resql = $this->db->query($sql);

                    if ($resql && $this->db->num_rows($resql)) {
                        dol_syslog(
                            "EmailImport: doublon " . $filename,
                            LOG_WARNING,
                        );
                        continue;
                    }

                    $json = $this->parsePdf($python, $filepath);

                    if (empty($json)) {
                        $email_ok = false;
                        continue;
                    }

                    $data = json_decode($json, true);

                    if (!$data) {
                        $email_ok = false;
                        continue;
                    }

                    $import = new InvoiceImport($this->db);
                    $id = $import->create($hash, $json);

                    if ($id <= 0) {
                        $email_ok = false;
                        continue;
                    }

                    $import->fetch($id);

                    $facture_id = $import->createSupplierInvoice(
                        $user,
                        $filepath,
                        $filename,
                        0,
                    );

                    if ($facture_id == -2) {
                        // fournisseur introuvable → on arrête TOUT pour cet email
                        dol_syslog(
                            "EmailImport: fournisseur introuvable → email laissé UNSEEN",
                            LOG_WARNING,
                        );

                        $email_ok = false;
                        break; // IMPORTANT
                    }

                    if ($facture_id <= 0) {
                        $email_ok = false;
                        continue;
                    }

                    $processed++;
                }

                if ($email_ok) {
                    imap_setflag_full($inbox, $email_number, "\\Seen");
                }
            }

            imap_close($inbox);

            return $processed;
        } finally {
            if (file_exists($lockfile)) {
                unlink($lockfile);
            }
        }
    }

    /**
     * Parse un PDF via le script Python configuré
     */
    public function parsePdf($python, $filepath)
    {
        if (!file_exists($python)) {
            dol_syslog("Python introuvable: " . $python, LOG_ERR);
            return null;
        }
        $script =
            DOL_DOCUMENT_ROOT . "/custom/invoiceimport/python/parse_invoice.py";

        if (!file_exists($script)) {
            dol_syslog(
                "EmailImport: script Python introuvable: " . $script,
                LOG_ERR,
            );
            return null;
        }

        $cmd =
            escapeshellcmd($python) .
            " " .
            escapeshellarg($script) .
            " " .
            escapeshellarg($filepath) .
            " 2>&1";

        dol_syslog("EmailImport: commande parser=" . $cmd, LOG_DEBUG);

        $output = [];
        $return_var = 0;

        exec($cmd, $output, $return_var);

        $json = trim(implode("\n", $output));

        if ($return_var !== 0) {
            dol_syslog(
                "EmailImport: erreur parser return=" .
                    $return_var .
                    " output=" .
                    $json,
                LOG_ERR,
            );
            return null;
        }

        return $json;
    }

    /**
     * Extrait toutes les pièces jointes PDF d'un email
     */
    private function extractPdfAttachments($inbox, $email_number, $structure)
    {
        $attachments = [];

        if (isset($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $this->extractPart(
                    $inbox,
                    $email_number,
                    $part,
                    (string) ($index + 1),
                    $attachments,
                );
            }
        }

        return $attachments;
    }

    /**
     * Extraction récursive des pièces jointes
     */
    private function extractPart(
        $inbox,
        $email_number,
        $part,
        $part_number,
        &$attachments,
    ) {
        if (isset($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $index => $subpart) {
                $this->extractPart(
                    $inbox,
                    $email_number,
                    $subpart,
                    $part_number . "." . ($index + 1),
                    $attachments,
                );
            }
        }

        $filename = $this->getPartFilename($part);

        if (empty($filename)) {
            return;
        }

        if (!preg_match('/\.pdf$/i', $filename)) {
            return;
        }

        $body = imap_fetchbody($inbox, $email_number, $part_number);

        if ($part->encoding == 3) {
            $body = base64_decode($body);
        } elseif ($part->encoding == 4) {
            $body = quoted_printable_decode($body);
        }

        $attachments[] = [
            "filename" => $filename,
            "content" => $body,
        ];
    }

    /**
     * Récupère le nom de fichier d'une partie MIME
     */
    private function getPartFilename($part)
    {
        $filename = "";

        if (!empty($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) == "filename") {
                    $filename = $param->value;
                    break;
                }
            }
        }

        if (empty($filename) && !empty($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) == "name") {
                    $filename = $param->value;
                    break;
                }
            }
        }

        if (!empty($filename)) {
            $filename = imap_utf8($filename);
        }

        return $filename;
    }
}
