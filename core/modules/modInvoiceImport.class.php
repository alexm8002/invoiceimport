<?php
include_once DOL_DOCUMENT_ROOT . "/core/modules/DolibarrModules.class.php";

class modInvoiceImport extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // ID unique (à adapter si conflit)
        $this->numero = 500000;

        // Nom et description
        $this->rights_class = "invoiceimport";
        $this->family = "srm";
        $this->module_position = "90";
        $this->name = preg_replace("/^mod/i", "", get_class($this));
        $this->description =
            "Import automatique de factures fournisseurs depuis email (PDF)";
        $this->version = "1.0.1";
        $this->const_name = "MAIN_MODULE_INVOICEIMPORT";

        // Icône
        $this->picto = "bill";

        // Dépendances
        $this->depends = ["modFournisseur"];
        $this->requiredby = [];
        $this->conflictwith = [];

        // Langues
        $this->langfiles = ["invoiceimport@invoiceimport"];

        // Répertoires créés à l’activation
        $this->dirs = [DOL_DATA_ROOT . "/invoiceimport"];

        // Config page
        $this->config_page_url = ["setup.php@invoiceimport"];

        // Modules parts
        $this->module_parts = [
            "cron" => 1,
            "triggers" => 1,
            "hooks" => ["thirdpartycard"],
        ];
        $this->cronjobs = [
            0 => [
                "label" => "EmailImport Cron",
                "jobtype" => "method",
                "class" => "/invoiceimport/class/emailimport.class.php",
                "objectname" => "EmailImport",
                "method" => "doScheduledJob",
                "parameters" => "",
                "comment" => "Import automatique des factures depuis IMAP",
                "frequency" => 5,
                "unitfrequency" => 60,
                "status" => 1,
                "test" => '$conf->invoiceimport->enabled',
                "priority" => 50,
            ],
        ];

        // Permissions
        $this->rights = [];
        $r = 0;

        $this->rights[$r][0] = 500001;
        $this->rights[$r][1] = "Lire les imports de factures";
        $this->rights[$r][2] = "r";
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = "read";
        $r++;

        $this->rights[$r][0] = 500002;
        $this->rights[$r][1] = "Créer des factures depuis import";
        $this->rights[$r][2] = "w";
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = "write";
        $r++;

        // Menus
        $this->menu = [];
        $r = 0;

        $this->menu[$r++] = [
            "fk_menu" => "fk_mainmenu=billing",
            "type" => "left",
            "titre" => "Imports factures",
            "mainmenu" => "billing",
            "leftmenu" => "invoiceimport",
            "url" => "/invoiceimport/index.php",
            "langs" => "invoiceimport@invoiceimport",
            "position" => 100,
            "enabled" => '$conf->invoiceimport->enabled',
            "perms" => '$user->rights->invoiceimport->read',
            "target" => "",
            "user" => 2,
        ];

        // Sous-menu config
        $this->menu[$r++] = [
            "fk_menu" => "fk_mainmenu=billing,fk_leftmenu=invoiceimport",
            "type" => "left",
            "titre" => "Configuration",
            "mainmenu" => "billing",
            "leftmenu" => "",
            "url" => "/invoiceimport/admin/setup.php",
            "langs" => "invoiceimport@invoiceimport",
            "position" => 101,
            "enabled" => '$conf->invoiceimport->enabled',
            "perms" => '$user->admin',
            "target" => "",
            "user" => 2,
        ];
    }

    /**
     * Fonction appelée à l'activation du module
     */
    public function init($options = "")
    {
        $sql = [];

        // Table principale
        $sql[] =
            "CREATE TABLE IF NOT EXISTS " .
            MAIN_DB_PREFIX .
            "invoiceimport (
             rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
             filename VARCHAR(255),
             status VARCHAR(20),
             parsed_json LONGTEXT,
             datec DATETIME
         )";

        $res = $this->_init($sql, $options);

        if ($res > 0) {
            $this->installPythonEnv();
        }

        return $res;
    }

    private function findPython()
    {
        $candidates = [
            "/usr/bin/python3",
            "/usr/local/bin/python3",
            "/bin/python3",
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        $output = [];
        exec("which python3 2>/dev/null", $output);

        if (!empty($output) && file_exists($output[0])) {
            return trim($output[0]);
        }

        return null;
    }

    private function installPythonEnv()
    {
        global $conf;

        $base = DOL_DOCUMENT_ROOT . "/custom/invoiceimport";
        $venv = $base . "/venv";
        $python = $this->findPython();

        if (empty($python)) {
            dol_syslog("Aucun python3 trouvé", LOG_ERR);
            return;
        }

        dol_syslog(
            "InvoiceImport: installation environnement Python avec " . $python,
            LOG_DEBUG,
        );

        // Création venv si nécessaire
        if (!file_exists($venv . "/bin/python")) {
            $cmd =
                escapeshellcmd($python) .
                " -m venv " .
                escapeshellarg($venv) .
                " 2>&1";
            exec($cmd, $out, $ret);

            if ($ret !== 0) {
                dol_syslog(
                    "Erreur création venv: " . implode("\n", $out),
                    LOG_ERR,
                );
                return;
            }
        }

        $pythonVenv = $venv . "/bin/python";
        $pip = $venv . "/bin/pip";

        // Vérification pip
        if (!file_exists($pip)) {
            dol_syslog("pip absent, tentative ensurepip", LOG_WARNING);

            $cmd = escapeshellcmd($pythonVenv) . " -m ensurepip 2>&1";
            exec($cmd, $out, $ret);

            dol_syslog("ensurepip: " . implode("\n", $out), LOG_DEBUG);
        }

        // Fallback ultime get-pip.py
        if (!file_exists($pip)) {
            dol_syslog("ensurepip échoué, fallback get-pip.py", LOG_WARNING);

            $getpip = $base . "/get-pip.py";

            if (!file_exists($getpip)) {
                $cmd =
                    "curl -sS https://bootstrap.pypa.io/get-pip.py -o " .
                    escapeshellarg($getpip) .
                    " 2>&1";
                exec($cmd, $out, $ret);

                dol_syslog(
                    "download get-pip.py: " . implode("\n", $out),
                    LOG_DEBUG,
                );

                if ($ret !== 0) {
                    dol_syslog("Impossible de télécharger get-pip.py", LOG_ERR);
                    return;
                }
            }

            $cmd =
                escapeshellcmd($pythonVenv) .
                " " .
                escapeshellarg($getpip) .
                " 2>&1";
            exec($cmd, $out, $ret);

            dol_syslog("get-pip install: " . implode("\n", $out), LOG_DEBUG);

            if ($ret !== 0 || !file_exists($pip)) {
                dol_syslog("Impossible d'installer pip", LOG_ERR);
                return;
            }
        }

        // Upgrade pip
        $cmd = escapeshellcmd($pip) . " install --upgrade pip 2>&1";
        exec($cmd, $out, $ret);

        dol_syslog("pip upgrade: " . implode("\n", $out), LOG_DEBUG);

        // Install requirements
        $cmd =
            escapeshellcmd($pip) .
            " install -r " .
            escapeshellarg($base . "/python/requirements.txt") .
            " 2>&1";
        exec($cmd, $out, $ret);

        dol_syslog("PIP INSTALL: " . implode("\n", $out), LOG_DEBUG);

        if ($ret !== 0) {
            dol_syslog("Erreur installation requirements", LOG_ERR);
            return;
        }

        dolibarr_set_const(
            $this->db,
            "INVOICEIMPORT_PYTHON_BIN",
            $pythonVenv,
            "chaine",
            0,
            "",
            $conf->entity,
        );

        dol_syslog(
            "InvoiceImport: Python prêt (" . $pythonVenv . ")",
            LOG_DEBUG,
        );
    }

    /**
     * Fonction appelée à la désactivation
     */
    public function remove($options = "")
    {
        global $db, $conf;

        $sql = [];

        $sql[] =
            "DELETE FROM " .
            MAIN_DB_PREFIX .
            "cronjob WHERE label = 'EmailImport Cron' AND entity = " .
            $conf->entity;

        return $this->_remove($sql, $options);
    }
}
