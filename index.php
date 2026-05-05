<?php

require_once $_SERVER["DOCUMENT_ROOT"] . "/main.inc.php";
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once "./class/invoiceimport.class.php";

llxHeader();

print load_fiche_titre("Import facture PDF");

// -------------------------
// Upload form
// -------------------------

print '<form method="POST" enctype="multipart/form-data">';
print '<input type="file" name="invoice_pdf" required>';
print '<input type="submit" name="import" class="button" value="Importer">';
print "</form>";

// -------------------------
// Traitement
// -------------------------

if (GETPOST("import", "alpha")) {
    if (!empty($_FILES["invoice_pdf"]["tmp_name"])) {
        $original_tmp = $_FILES["invoice_pdf"]["tmp_name"];
        $original_name = $_FILES["invoice_pdf"]["name"];

        // -------------------------
        // Copie fichier (FIX IMPORTANT)
        // -------------------------
        $tmp_pdf = DOL_DATA_ROOT . "/temp_invoice_" . time() . ".pdf";

        if (!copy($original_tmp, $tmp_pdf)) {
            setEventMessages("Erreur copie fichier", null, "errors");
        } else {
            // -------------------------
            // Appel Python
            // -------------------------
            $cmd =
                "/var/www/gestion2m.2mprodelec.fr/htdocs/custom/invoiceimport/venv/bin/python " .
                "/var/www/gestion2m.2mprodelec.fr/htdocs/custom/invoiceimport/python/parse_invoice.py " .
                escapeshellarg($tmp_pdf) .
                " 2>&1";

            exec($cmd, $output);

            $json = trim(implode("\n", $output));

            $data = json_decode($json, true);

            if (!$data) {
                setEventMessages("Erreur parsing JSON", null, "errors");
                print "<pre>" . $json . "</pre>";
            } else {
                // -------------------------
                // Sauvegarde import
                // -------------------------
                $import = new InvoiceImport($db);
                $id = $import->create($original_name, $json);

                if ($id > 0) {
                    $import->fetch($id);

                    // -------------------------
                    // Création facture
                    // -------------------------
                    $facture_id = $import->createSupplierInvoice(
                        $user,
                        $tmp_pdf,
                        $original_name,
                        0,
                    );

                    if ($facture_id > 0) {
                        setEventMessages(
                            "Facture créée ID=" . $facture_id,
                            null,
                        );
                    } else {
                        setEventMessages(
                            "Erreur création facture",
                            null,
                            "errors",
                        );
                    }
                } else {
                    setEventMessages(
                        "Erreur enregistrement import",
                        null,
                        "errors",
                    );
                }
            }
        }
    }
}

llxFooter();
