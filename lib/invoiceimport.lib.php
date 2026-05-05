<?php

function invoiceimportAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load("invoiceimport@invoiceimport");

    $h = 0;
    $head = [];

    // Onglet configuration
    $head[$h][0] = DOL_URL_ROOT . "/custom/invoiceimport/admin/setup.php";
    $head[$h][1] = $langs->trans("Settings");
    $head[$h][2] = "settings";
    $h++;

    return $head;
}
