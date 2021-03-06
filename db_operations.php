<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * handles miscellaneous db operations:
 *  - move/rename
 *  - copy
 *  - changing collation
 *  - changing comment
 *  - adding tables
 *  - viewing PDF schemas
 *
 * @package PhpMyAdmin
 */
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Message;
use PhpMyAdmin\Plugins\Export\ExportSql;
use PhpMyAdmin\Operations;
use PhpMyAdmin\Response;
use PhpMyAdmin\Util;

/**
 * requirements
 */
require_once 'libraries/common.inc.php';
require_once 'libraries/display_create_table.lib.php';

/**
 * functions implementation for this script
 */
require_once 'libraries/check_user_privileges.lib.php';

// add a javascript file for jQuery functions to handle Ajax actions
$response = Response::getInstance();
$header = $response->getHeader();
$scripts = $header->getScripts();
$scripts->addFile('db_operations.js');

$sql_query = '';

/**
 * Rename/move or copy database
 */
if (strlen($GLOBALS['db']) > 0
    && (! empty($_REQUEST['db_rename']) || ! empty($_REQUEST['db_copy']))
) {
    if (! empty($_REQUEST['db_rename'])) {
        $move = true;
    } else {
        $move = false;
    }

    if (! isset($_REQUEST['newname']) || strlen($_REQUEST['newname']) === 0) {
        $message = Message::error(__('The database name is empty!'));
    } else if($_REQUEST['newname'] === $_REQUEST['db']) {
        $message = Message::error(
            __('Cannot copy database to the same name. Change the name and try again.')
        );
    } else {
        $_error = false;
        if ($move || ! empty($_REQUEST['create_database_before_copying'])) {
            Operations::createDbBeforeCopy();
        }

        // here I don't use DELIMITER because it's not part of the
        // language; I have to send each statement one by one

        // to avoid selecting alternatively the current and new db
        // we would need to modify the CREATE definitions to qualify
        // the db name
        Operations::runProcedureAndFunctionDefinitions($GLOBALS['db']);

        // go back to current db, just in case
        $GLOBALS['dbi']->selectDb($GLOBALS['db']);

        $tables_full = $GLOBALS['dbi']->getTablesFull($GLOBALS['db']);

        include_once "libraries/plugin_interface.lib.php";
        // remove all foreign key constraints, otherwise we can get errors
        /* @var $export_sql_plugin ExportSql */
        $export_sql_plugin = PMA_getPlugin(
            "export",
            "sql",
            'libraries/classes/Plugins/Export/',
            array(
                'single_table' => isset($single_table),
                'export_type'  => 'database'
            )
        );

        // create stand-in tables for views
        $views = Operations::getViewsAndCreateSqlViewStandIn(
            $tables_full, $export_sql_plugin, $GLOBALS['db']
        );

        // copy tables
        $sqlConstratints = Operations::copyTables(
            $tables_full, $move, $GLOBALS['db']
        );

        // handle the views
        if (! $_error) {
            Operations::handleTheViews($views, $move, $GLOBALS['db']);
        }
        unset($views);

        // now that all tables exist, create all the accumulated constraints
        if (! $_error && count($sqlConstratints) > 0) {
            Operations::createAllAccumulatedConstraints($sqlConstratints);
        }
        unset($sqlConstratints);

        if ($GLOBALS['dbi']->getVersion() >= 50100) {
            // here DELIMITER is not used because it's not part of the
            // language; each statement is sent one by one

            Operations::runEventDefinitionsForDb($GLOBALS['db']);
        }

        // go back to current db, just in case
        $GLOBALS['dbi']->selectDb($GLOBALS['db']);

        // Duplicate the bookmarks for this db (done once for each db)
        Operations::duplicateBookmarks($_error, $GLOBALS['db']);

        if (! $_error && $move) {
            if (isset($_REQUEST['adjust_privileges'])
                && ! empty($_REQUEST['adjust_privileges'])
            ) {
                Operations::adjustPrivilegesMoveDb($GLOBALS['db'], $_REQUEST['newname']);
            }

            /**
             * cleanup pmadb stuff for this db
             */
            include_once 'libraries/relation_cleanup.lib.php';
            PMA_relationsCleanupDatabase($GLOBALS['db']);

            // if someday the RENAME DATABASE reappears, do not DROP
            $local_query = 'DROP DATABASE '
                . Util::backquote($GLOBALS['db']) . ';';
            $sql_query .= "\n" . $local_query;
            $GLOBALS['dbi']->query($local_query);

            $message = Message::success(
                __('Database %1$s has been renamed to %2$s.')
            );
            $message->addParam($GLOBALS['db']);
            $message->addParam($_REQUEST['newname']);
        } elseif (! $_error) {
            if (isset($_REQUEST['adjust_privileges'])
                && ! empty($_REQUEST['adjust_privileges'])
            ) {
                Operations::adjustPrivilegesCopyDb($GLOBALS['db'], $_REQUEST['newname']);
            }

            $message = Message::success(
                __('Database %1$s has been copied to %2$s.')
            );
            $message->addParam($GLOBALS['db']);
            $message->addParam($_REQUEST['newname']);
        } else {
            $message = Message::error();
        }
        $reload     = true;

        /* Change database to be used */
        if (! $_error && $move) {
            $GLOBALS['db'] = $_REQUEST['newname'];
        } elseif (! $_error) {
            if (isset($_REQUEST['switch_to_new'])
                && $_REQUEST['switch_to_new'] == 'true'
            ) {
                $GLOBALS['PMA_Config']->setCookie('pma_switch_to_new', 'true');
                $GLOBALS['db'] = $_REQUEST['newname'];
            } else {
                $GLOBALS['PMA_Config']->setCookie('pma_switch_to_new', '');
            }
        }
    }

    /**
     * Database has been successfully renamed/moved.  If in an Ajax request,
     * generate the output with {@link PhpMyAdmin\Response} and exit
     */
    if ($response->isAjax()) {
        $response->setRequestStatus($message->isSuccess());
        $response->addJSON('message', $message);
        $response->addJSON('newname', $_REQUEST['newname']);
        $response->addJSON(
            'sql_query',
            Util::getMessage(null, $sql_query)
        );
        $response->addJSON('db', $GLOBALS['db']);
        exit;
    }
}

/**
 * Settings for relations stuff
 */

$cfgRelation = PMA_getRelationsParam();

/**
 * Check if comments were updated
 * (must be done before displaying the menu tabs)
 */
if (isset($_REQUEST['comment'])) {
    PMA_setDbComment($GLOBALS['db'], $_REQUEST['comment']);
}

require 'libraries/db_common.inc.php';
$url_query .= '&amp;goto=db_operations.php';

// Gets the database structure
$sub_part = '_structure';

list(
    $tables,
    $num_tables,
    $total_num_tables,
    $sub_part,
    $is_show_stats,
    $db_is_system_schema,
    $tooltip_truename,
    $tooltip_aliasname,
    $pos
) = Util::getDbInfo($db, isset($sub_part) ? $sub_part : '');

echo "\n";

if (isset($message)) {
    echo Util::getMessage($message, $sql_query);
    unset($message);
}

$_REQUEST['db_collation'] = $GLOBALS['dbi']->getDbCollation($GLOBALS['db']);
$is_information_schema = $GLOBALS['dbi']->isSystemSchema($GLOBALS['db']);

if (!$is_information_schema) {
    if ($cfgRelation['commwork']) {
        /**
         * database comment
         */
        $response->addHTML(Operations::getHtmlForDatabaseComment($GLOBALS['db']));
    }

    $response->addHTML('<div>');
    $response->addHTML(PMA_getHtmlForCreateTable($db));
    $response->addHTML('</div>');

    /**
     * rename database
     */
    if ($GLOBALS['db'] != 'mysql') {
        $response->addHTML(Operations::getHtmlForRenameDatabase($GLOBALS['db']));
    }

    // Drop link if allowed
    // Don't even try to drop information_schema.
    // You won't be able to. Believe me. You won't.
    // Don't allow to easily drop mysql database, RFE #1327514.
    if (($is_superuser || $GLOBALS['cfg']['AllowUserDropDatabase'])
        && ! $db_is_system_schema
        && $GLOBALS['db'] != 'mysql'
    ) {
        $response->addHTML(Operations::getHtmlForDropDatabaseLink($GLOBALS['db']));
    }
    /**
     * Copy database
     */
    $response->addHTML(Operations::getHtmlForCopyDatabase($GLOBALS['db']));

    /**
     * Change database charset
     */
    $response->addHTML(Operations::getHtmlForChangeDatabaseCharset($GLOBALS['db'], $table));

    if (! $cfgRelation['allworks']
        && $cfg['PmaNoRelation_DisableWarning'] == false
    ) {
        $message = Message::notice(
            __(
                'The phpMyAdmin configuration storage has been deactivated. ' .
                '%sFind out why%s.'
            )
        );
        $message->addParamHtml('<a href="./chk_rel.php' . $url_query . '">');
        $message->addParamHtml('</a>');
        /* Show error if user has configured something, notice elsewhere */
        if (!empty($cfg['Servers'][$server]['pmadb'])) {
            $message->isError(true);
        }
    } // end if
} // end if (!$is_information_schema)

// not sure about displaying the PDF dialog in case db is information_schema
if ($cfgRelation['pdfwork'] && $num_tables > 0) {
    // We only show this if we find something in the new pdf_pages table
    $test_query = '
        SELECT *
        FROM ' . Util::backquote($GLOBALS['cfgRelation']['db'])
        . '.' . Util::backquote($cfgRelation['pdf_pages']) . '
        WHERE db_name = \'' . $GLOBALS['dbi']->escapeString($GLOBALS['db'])
        . '\'';
    $test_rs = PMA_queryAsControlUser(
        $test_query,
        false,
        DatabaseInterface::QUERY_STORE
    );
} // end if
