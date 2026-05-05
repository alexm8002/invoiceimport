<?php

require_once DOL_DOCUMENT_ROOT . "/core/class/commonobject.class.php";

class InvoiceImport extends CommonObject
{
    public $element = "invoiceimport";
    public $table_element = "invoiceimport";

    public $rowid;
    public $filename;
    public $status;
    public $parsed_json;
    public $datec;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function fetch($id)
    {
        $sql = "SELECT rowid, filename, status, parsed_json, datec";
        $sql .= " FROM " . MAIN_DB_PREFIX . "invoiceimport";
        $sql .= " WHERE rowid = " . ((int) $id);

        $resql = $this->db->query($sql);

        if ($resql && $this->db->num_rows($resql)) {
            $obj = $this->db->fetch_object($resql);

            $this->rowid = $obj->rowid;
            $this->filename = $obj->filename;
            $this->status = $obj->status;
            $this->parsed_json = $obj->parsed_json;
            $this->datec = $this->db->jdate($obj->datec);

            return 1;
        }

        return 0;
    }

    public function create($filename, $parsed_json)
    {
        $sql =
            "INSERT INTO " .
            MAIN_DB_PREFIX .
            "invoiceimport (filename, status, parsed_json, datec)";
        $sql .= " VALUES (";
        $sql .= "'" . $this->db->escape($filename) . "',";
        $sql .= "'pending',";
        $sql .= "'" . $this->db->escape($parsed_json) . "',";
        $sql .= "NOW()";
        $sql .= ")";

        if ($this->db->query($sql)) {
            return $this->db->last_insert_id(MAIN_DB_PREFIX . "invoiceimport");
        }

        return -1;
    }

    /**
     * Création facture + attachement PDF
     */
    public function createSupplierInvoice(
        $user,
        $filepath = null,
        $filename = null,
        $error = 0,
    ) {
        global $conf;

        require_once DOL_DOCUMENT_ROOT .
            "/fourn/class/fournisseur.facture.class.php";
        require_once DOL_DOCUMENT_ROOT . "/core/lib/files.lib.php";

        dol_syslog("InvoiceImport: START createSupplierInvoice", LOG_DEBUG);
        dol_syslog("InvoiceImport: filepath=" . $filepath, LOG_DEBUG);
        dol_syslog("InvoiceImport: filename=" . $filename, LOG_DEBUG);
        dol_syslog("InvoiceImport: upload_error=" . $error, LOG_DEBUG);

        $data = json_decode($this->parsed_json, true);

        if (!$data) {
            dol_syslog("InvoiceImport: JSON invalide", LOG_ERR);
            return -1;
        }

        $socid = 0;

        // 🔹 CAS 1 : import manuel (prioritaire)
        if (!empty($data["forced_socid"])) {
            $socid = (int) $data["forced_socid"];

            dol_syslog(
                "InvoiceImport: fournisseur forcé socid=" . $socid,
                LOG_DEBUG,
            );
        }
        // 🔹 CAS 2 : auto via SIREN
        else {
            $siren = $data["siren"] ?? "";

            dol_syslog("InvoiceImport: SIREN=" . $siren, LOG_DEBUG);

            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe";
            $sql .=
                " WHERE siren = '" . $this->db->escape($siren) . "' LIMIT 1";

            $resql = $this->db->query($sql);

            if (!$resql || $this->db->num_rows($resql) == 0) {
                dol_syslog(
                    "InvoiceImport: fournisseur introuvable SIREN=" . $siren,
                    LOG_ERR,
                );

                $this->setStatus("unseen");
                return -2;
            }

            $obj = $this->db->fetch_object($resql);
            $socid = $obj->rowid;
        }

        if (empty($data["lines"])) {
            dol_syslog("InvoiceImport: aucune ligne détectée", LOG_ERR);
            return -3;
        }

        $facture = new FactureFournisseur($this->db);

        $facture->socid = $socid;
        $facture->ref_supplier = !empty($data["ref"])
            ? $data["ref"]
            : "IMP-" . time();
        $facture->multicurrency_code = "EUR";

        $d = $data["date"];

        $facture->date = dol_mktime(
            0,
            0,
            0,
            substr($d, 3, 2),
            substr($d, 0, 2),
            substr($d, 6, 4),
        );

        dol_syslog(
            "InvoiceImport: création facture ref_supplier=" .
                $facture->ref_supplier,
            LOG_DEBUG,
        );

        $res = $facture->create($user);

        if ($res <= 0) {
            dol_syslog(
                "InvoiceImport: erreur création facture: " . $facture->error,
                LOG_ERR,
            );
            dol_syslog(
                "InvoiceImport: errors=" . print_r($facture->errors, true),
                LOG_ERR,
            );
            return -4;
        }

        dol_syslog(
            "InvoiceImport: facture créée id=" .
                $facture->id .
                " ref=" .
                $facture->ref,
            LOG_DEBUG,
        );

        foreach ($data["lines"] as $line) {
            $desc = $line["desc"] ?? "";
            $qty = $line["qty"] ?? 1;
            $price = $line["price_ht"] ?? 0;
            $tva = $line["tva"] ?? 20;

            dol_syslog(
                "InvoiceImport: addline desc=" .
                    $desc .
                    " price=" .
                    $price .
                    " tva=" .
                    $tva .
                    " qty=" .
                    $qty,
                LOG_DEBUG,
            );

            $resline = $facture->addline($desc, $price, $tva, 0, 0, $qty);

            if ($resline < 0) {
                dol_syslog(
                    "InvoiceImport: ADDLINE ERROR: " . $facture->error,
                    LOG_ERR,
                );
                dol_syslog(
                    "InvoiceImport: ADDLINE ERRORS=" .
                        print_r($facture->errors, true),
                    LOG_ERR,
                );
                return -6;
            }
        }

        $facture->update_price(1);

        dol_syslog("InvoiceImport: prix facture mis à jour", LOG_DEBUG);

        // Validation facture
        $resval = $facture->validate($user);

        if ($resval <= 0) {
            dol_syslog(
                "InvoiceImport: erreur validation facture: " . $facture->error,
                LOG_ERR,
            );
            dol_syslog(
                "InvoiceImport: validate errors=" .
                    print_r($facture->errors, true),
                LOG_ERR,
            );
            return -7;
        }

        // Re-fetch obligatoire pour récupérer la ref définitive
        $facture->fetch($facture->id);

        dol_syslog(
            "InvoiceImport: facture validée id=" .
                $facture->id .
                " ref=" .
                $facture->ref,
            LOG_DEBUG,
        );

        // -------------------------
        // Attachement PDF FINAL OK
        // -------------------------
        if ($filepath && $filename) {
            dol_syslog("ATTACH START", LOG_DEBUG);
            dol_syslog("filepath=" . $filepath, LOG_DEBUG);

            if (!file_exists($filepath)) {
                dol_syslog("FICHIER INTROUVABLE", LOG_ERR);
                return -20;
            }

            require_once DOL_DOCUMENT_ROOT . "/core/lib/files.lib.php";

            $refclean = dol_sanitizeFileName($facture->ref);

            $dir =
                $conf->fournisseur->facture->dir_output .
                "/" .
                get_exdir($facture->id, 2, 0, 0, $facture, "invoice_supplier") .
                $refclean;

            dol_syslog("DIR=" . $dir, LOG_DEBUG);

            if (!is_dir($dir)) {
                dol_mkdir($dir);
            }

            if (!is_writable($dir)) {
                dol_syslog("DIR NON WRITABLE=" . $dir, LOG_ERR);
                return -22;
            }

            $newname = dol_sanitizeFileName($facture->ref . "-" . $filename);
            $dest = $dir . "/" . $newname;

            dol_syslog("DEST=" . $dest, LOG_DEBUG);

            if (!copy($filepath, $dest)) {
                dol_syslog("ECHEC COPY", LOG_ERR);
                dol_syslog(print_r(error_get_last(), true), LOG_ERR);
                return -23;
            }

            if (!file_exists($dest)) {
                dol_syslog("FICHIER NON PRESENT APRES COPY", LOG_ERR);
                return -24;
            }

            dol_syslog("ATTACH OK", LOG_DEBUG);
        }

        $this->setStatus("processed");

        dol_syslog(
            "InvoiceImport: END OK facture_id=" . $facture->id,
            LOG_DEBUG,
        );

        return $facture->id;
    }

    public function setStatus($status)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "invoiceimport";
        $sql .= " SET status = '" . $this->db->escape($status) . "'";
        $sql .= " WHERE rowid = " . ((int) $this->rowid);

        return $this->db->query($sql);
    }
}
