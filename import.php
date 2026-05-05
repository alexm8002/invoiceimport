<?php

require "../../main.inc.php";
require_once DOL_DOCUMENT_ROOT .
    "/custom/invoiceimport/class/emailimport.class.php";
require_once DOL_DOCUMENT_ROOT .
    "/custom/invoiceimport/class/invoiceimport.class.php";

$socid = GETPOST("socid", "int");

if (empty($socid)) {
    setEventMessages("Fournisseur manquant", null, "errors");
    header("Location: " . $_SERVER["HTTP_REFERER"]);
    exit();
}

/**
 * =========================
 * MODE AFFICHAGE FORMULAIRE
 * =========================
 */
if (empty($_FILES)) {
    llxHeader("", "Import facture");

    print load_fiche_titre("Importer une facture PDF");

    print '<form method="POST" enctype="multipart/form-data">';
    print '<input type="hidden" name="socid" value="' . $socid . '">';
    print '<input type="file" name="invoice_pdf" accept="application/pdf" required>';
    print "<br><br>";
    print '<input type="submit" class="button" value="Importer facture">';
    print "</form>";

    llxFooter();
    exit();
}

/**
 * =========================
 * MODE TRAITEMENT
 * =========================
 */
if (empty($_FILES["invoice_pdf"]["tmp_name"])) {
    setEventMessages("Aucun fichier", null, "errors");
    header("Location: " . DOL_URL_ROOT . "/fourn/card.php?socid=" . $socid);
    exit();
}

$filepath = $_FILES["invoice_pdf"]["tmp_name"];
$filename = $_FILES["invoice_pdf"]["name"];

// Parser Python
$python = getDolGlobalString("INVOICEIMPORT_PYTHON_BIN");
if (empty($python)) {
    $python = DOL_DOCUMENT_ROOT . "/custom/invoiceimport/venv/bin/python";
}

$emailImport = new EmailImport($db);
$json = $emailImport->parsePdf($python, $filepath);

if (empty($json)) {
    setEventMessages("Erreur parsing PDF", null, "errors");
    header("Location: " . DOL_URL_ROOT . "/fourn/card.php?socid=" . $socid);
    exit();
}

$data = json_decode($json, true);

if (!$data) {
    setEventMessages("Erreur parsing JSON", null, "errors");
    header("Location: " . DOL_URL_ROOT . "/fourn/card.php?socid=" . $socid);
    exit();
}

// Forcer le fournisseur
$data["forced_socid"] = $socid;

// Stocker en base
$import = new InvoiceImport($db);
$id = $import->create($filename, json_encode($data));
$import->fetch($id);

// Création facture
$res = $import->createSupplierInvoice($user, $filepath, $filename, 0);

if ($res > 0) {
    setEventMessages("Facture créée", null, "mesgs");
} else {
    setEventMessages("Erreur import", null, "errors");
}

// Retour fiche fournisseur
header("Location: " . DOL_URL_ROOT . "/fourn/card.php?socid=" . $socid);
exit();
