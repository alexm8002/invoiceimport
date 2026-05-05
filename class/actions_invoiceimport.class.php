<?php

class ActionsInvoiceImport
{
    function addMoreActionsButtons(
        $parameters,
        &$object,
        &$action,
        $hookmanager,
    ) {
        if ($object->element == "societe") {
            print '<div class="inline-block divButAction">';

            print '<a class="butAction" href="/custom/invoiceimport/import.php?socid=' .
                $object->id .
                '">
                Importer facture
            </a>';

            print "</div>";
        }

        return 0;
    }
}
