<?php

/**
 * This file has all the main functions in it that set up the database connection
 * and initializes the appropriate adapters.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0 Beta 2
 *
 */

/**
 * Initialize database classes and connection.
 *
 * @param string $db_server
 * @param string $db_name
 * @param string $db_user
 * @param string $db_passwd
 * @param string $db_prefix
 * @param array $db_options
 * @param string $db_type
 * @return null
 */
function elk_db_initiate($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options = array(), $db_type = 'mysql')
{
	require_once(SOURCEDIR . '/database/Db.php');
	require_once(SOURCEDIR . '/database/Db-' . $db_type . '.class.php');

	return call_user_func_array(array('Database_' . DB_TYPE, 'initiate'), array($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options));
}

/**
 * Extend the database functionality.
 *
 * @param string $type = 'extra'
 */
function db_extend($type = 'extra')
{
	// this can be removed.
}

/**
 * Retrieve existing instance of the active database class.
 *
 * @return Database
 */
function database()
{
	return call_user_func(array('Database_' . DB_TYPE, 'db'));
}

/**
 * This function retrieves an existing instance of DbTable
 * and returns it.
 *
 * @return DbTable
 */
function db_table()
{
	global $db_type;

	require_once(SOURCEDIR . '/database/DbTable.class.php');
	require_once(SOURCEDIR . '/database/DbTable-' . $db_type . '.php');

	return call_user_func(array('DbTable_' . DB_TYPE, 'db_table'));
}

/**
 * This function returns an instance of DbSearch,
 * specifically designed for database utilities related to search.
 *
 * @return DbSearch
 *
 */
function db_search()
{
	global $db_type;

	require_once(SOURCEDIR . '/database/DbSearch.php');
	require_once(SOURCEDIR . '/database/DbSearch-' . $db_type . '.php');

	return call_user_func(array('DbSearch_' . DB_TYPE, 'db_search'));
}