<?php
/* Copyright ...
 */

require "../../../main.inc.php";

require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT .
    "/custom/invoiceimport/lib/invoiceimport.lib.php";

$langs->loadLangs(["admin", "invoiceimport@invoiceimport"]);

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST("action", "aZ09");

/*
 * Actions
 */
if ($action == "save") {
    dolibarr_set_const(
        $db,
        "INVOICEIMPORT_IMAP_HOST",
        GETPOST("imap_host", "alphanohtml"),
        "chaine",
        0,
        "",
        $conf->entity,
    );
    dolibarr_set_const(
        $db,
        "INVOICEIMPORT_IMAP_PORT",
        GETPOST("imap_port", "int"),
        "chaine",
        0,
        "",
        $conf->entity,
    );
    dolibarr_set_const(
        $db,
        "INVOICEIMPORT_IMAP_USER",
        GETPOST("imap_user", "alphanohtml"),
        "chaine",
        0,
        "",
        $conf->entity,
    );
    dolibarr_set_const(
        $db,
        "INVOICEIMPORT_IMAP_PASS",
        GETPOST("imap_pass", "alphanohtml"),
        "chaine",
        0,
        "",
        $conf->entity,
    );
    dolibarr_set_const(
        $db,
        "INVOICEIMPORT_IMAP_MAILBOX",
        GETPOST("imap_mailbox", "alphanohtml"),
        "chaine",
        0,
        "",
        $conf->entity,
    );
    dolibarr_set_const(
        $db,
        "INVOICEIMPORT_PDF_DIR",
        GETPOST("pdf_dir", "restricthtml"),
        "chaine",
        0,
        "",
        $conf->entity,
    );
    dolibarr_set_const(
        $db,
        "INVOICEIMPORT_PYTHON_BIN",
        GETPOST("python_bin", "alphanohtml"),
        "chaine",
        0,
        "",
        $conf->entity,
    );

    setEventMessages("Configuration enregistrée", null, "mesgs");
}
if ($action == "testimap") {
    $host = getDolGlobalString("INVOICEIMPORT_IMAP_HOST");
    $port = getDolGlobalString("INVOICEIMPORT_IMAP_PORT", "993");
    $userimap = getDolGlobalString("INVOICEIMPORT_IMAP_USER");
    $passimap = getDolGlobalString("INVOICEIMPORT_IMAP_PASS");
    $mailbox = getDolGlobalString("INVOICEIMPORT_IMAP_MAILBOX", "INBOX");

    $mbox = "{" . $host . ":" . $port . "/imap/ssl/novalidate-cert}" . $mailbox;

    $inbox = @imap_open($mbox, $userimap, $passimap);

    if (!$inbox) {
        setEventMessages("Erreur IMAP : " . imap_last_error(), null, "errors");
    } else {
        $num = imap_num_msg($inbox);
        setEventMessages(
            "Connexion IMAP OK (" . $num . " emails dans la boîte)",
            null,
            "mesgs",
        );
        imap_close($inbox);
    }
}

/*
 * View
 */
$title = "Configuration InvoiceImport";

llxHeader("", $title);

print load_fiche_titre($title, "", "title_setup");

$head = invoiceimportAdminPrepareHead();
print dol_get_fiche_head($head, "settings", $title, -1, "bill");

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';

print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print "<td>Paramètre</td>";
print "<td>Valeur</td>";
print "</tr>";

print '<tr><td>Serveur IMAP</td><td><input class="minwidth300" type="text" name="imap_host" value="' .
    dol_escape_htmltag(getDolGlobalString("INVOICEIMPORT_IMAP_HOST")) .
    '"></td></tr>';

print '<tr><td>Port IMAP</td><td><input class="width75" type="text" name="imap_port" value="' .
    dol_escape_htmltag(getDolGlobalString("INVOICEIMPORT_IMAP_PORT", "993")) .
    '"></td></tr>';

print '<tr><td>Utilisateur IMAP</td><td><input class="minwidth300" type="text" name="imap_user" value="' .
    dol_escape_htmltag(getDolGlobalString("INVOICEIMPORT_IMAP_USER")) .
    '"></td></tr>';

print '<tr><td>Mot de passe IMAP</td><td><input class="minwidth300" type="password" name="imap_pass" value="' .
    dol_escape_htmltag(getDolGlobalString("INVOICEIMPORT_IMAP_PASS")) .
    '"></td></tr>';

print '<tr><td>Boîte / dossier IMAP</td><td><input class="minwidth300" type="text" name="imap_mailbox" value="' .
    dol_escape_htmltag(
        getDolGlobalString("INVOICEIMPORT_IMAP_MAILBOX", "INBOX"),
    ) .
    '"></td></tr>';

print '<tr><td>Dossier de stockage PDF</td><td><input class="minwidth500" type="text" name="pdf_dir" value="' .
    dol_escape_htmltag(
        getDolGlobalString(
            "INVOICEIMPORT_PDF_DIR",
            DOL_DATA_ROOT . "/invoiceimport",
        ),
    ) .
    '"></td></tr>';

print '<tr><td>Chemin Python</td><td><input class="minwidth300" type="text" name="python_bin" value="' .
    dol_escape_htmltag(
        getDolGlobalString(
            "INVOICEIMPORT_PYTHON_BIN",
            DOL_DOCUMENT_ROOT . "/custom/invoiceimport/venv/bin/python",
        ),
    ) .
    '"></td></tr>';

print "</table>";

print "<br>";
print '<div class="center">';
print '<input type="submit" name="action" value="save" class="button button-save">';
print " ";

print '<button type="submit" name="action" value="testimap" class="button">Tester connexion IMAP</button>';
print "</div>";

print "</form>";

print dol_get_fiche_end();

llxFooter();
$db->close();
