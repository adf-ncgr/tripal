<?php

/**
 * @file
 * Functions to install chado schema through Drupal
 */

/**
 * Load Chado Schema 1.11 Form
 *
 * @ingroup tripal_core
 */
function tripal_core_chado_load_form() {

  $form['description'] = array(
    '#type' => 'item',
    '#value' => t("<font color=\"red\">WARNING:</font> A new install of Chado v1.2 or v1.11 "
      ."will install Chado within the Drupal database in a \"chado\" schema. If the \"chado\" schema already exists it will "
      ."be overwritten and all data will be lost.  You may choose to update an existing Chado v1.11 if it was installed with a previous "
      ."version of Tripal (e.g. v0.3b or v0.3.1). The update will not erase any data. "
      ."If you are using chado in a database external to the "
      ."Drupal database with a 'chado' entry in the 'settings.php' \$db_url argument "
      ."then Chado will be installed but will not be used .  The external "
      ."database specified in the settings.php file takes precedence."),
    '#weight' => 1,
  );

  $form['action_to_do'] = array(
     '#type' => 'radios',
     '#title' => 'Installation/Upgrade Action',
     '#options' => array(
        'Chado v1.2' => t('New Install of Chado v1.2 (erases all existing Chado data if Chado already exists)'),
        'Upgrade v1.11 to v1.2' => t('Upgrade existing Chado v1.11 to v1.2 (no data is lost)'),
        'Chado v1.11' => t('New Install of Chado v1.11 (erases all existing Chado data if Chado already exists)')
     ),
     '#description' => t('Select an action to perform'),
     '#required' => TRUE
     
  );

  $form['button'] = array(
    '#type' => 'submit',
    '#value' => t('Install/Upgrade Chado'),
    '#weight' => 2,
  );

  return $form;
}

/**
 * Submit Load Chado Schema 1.11 Form
 *
 * @ingroup tripal_core
 */
function tripal_core_chado_v1_11_load_form_submit($form, &$form_state) {
  global $user;
  $action_to_do   = trim($form_state['values']['action_to_do']);

  $args = array($action_to_do);
  tripal_add_job("Install Chado", 'tripal_core',
    'tripal_core_install_chado', $args, $user->uid);
}

/**
 * Install Chado Schema
 *
 * @ingroup tripal_core
 */
function tripal_core_install_chado() {
  $schema_file = drupal_get_path('module', 'tripal_core') . '/default_schema.sql';
  $init_file = drupal_get_path('module', 'tripal_core') . '/initialize.sql';

  if (tripal_core_reset_chado_schema()) {
    tripal_core_install_sql($schema_file);
    tripal_core_install_sql($init_file);
  }
  else {
    print "ERROR: cannot install chado.  Please check database permissions\n";
    exit;
  }
}

/**
 * Reset the Chado Schema
 * This drops the current chado and chado-related schema and re-creates it
 *
 * @ingroup tripal_core
 */
function tripal_core_reset_chado_schema() {
  global $active_db;

  // drop current chado and chado-related schema
  if (tripal_core_schema_exists('chado')) {
    print "Dropping existing 'chado' schema\n";
    pg_query($active_db, "drop schema chado cascade");
  }
  if (tripal_core_schema_exists('genetic_code')) {
    print "Dropping existing 'genetic_code' schema\n";
    pg_query($active_db, "drop schema genetic_code cascade");
  }
  if (tripal_core_schema_exists('so')) {
    print "Dropping existing 'so' schema\n";
    pg_query($active_db, "drop schema so cascade");
  }
  if (tripal_core_schema_exists('frange')) {
    print "Dropping existing 'frange' schema\n";
    pg_query($active_db, "drop schema frange cascade");
  }

  // create the new chado schema
  print "Creating 'chado' schema\n";
  pg_query($active_db, "create schema chado");
  if (tripal_core_schema_exists('chado')) {
    pg_query($active_db, "create language plpgsql");
    return TRUE;
  }

  return FALSE;
}

/**
 * Check that a given schema exists
 *
 * @param $schema
 *   The name of the schema to check the existence of
 *
 * @return
 *   TRUE/FALSE depending upon whether or not the schema exists
 *
 * @ingroup tripal_core
 */
function tripal_core_schema_exists($schema) {

  // check that the chado schema now exists
  $sql = "SELECT nspname
         FROM pg_namespace
         WHERE has_schema_privilege(nspname, 'USAGE') and nspname = '%s'
         ORDER BY nspname";
  $name = db_fetch_object(db_query($sql, $schema));
  if (strcmp($name->nspname, $schema) != 0) {
    return FALSE;
  }

  return TRUE;
}

/**
 * Execute the provided SQL
 *
 * @param $sql_file
 *   Contains SQL statements to be executed
 *
 * @ingroup tripal_core
 */
function tripal_core_install_sql($sql_file) {
  global $active_db;

  pg_query($active_db, "set search_path to chado,public");
  print "Loading $sql_file...\n";
  $lines = file($sql_file, FILE_SKIP_EMPTY_LINES);

  if (!$lines) {
    return 'Cannot open $schema_file';
  }

  $stack = array();
  $in_string = 0;
  $query = '';
  $i = 0;
  foreach ($lines as $line_num => $line) {
    $i++;
    $type = '';
    // find and remove comments except when inside of strings
    if (preg_match('/--/', $line) and !$in_string and !preg_match("/'.*?--.*?'/", $line)) {
      $line = preg_replace('/--.*$/', '', $line);  // remove comments
    }
    if (preg_match('/\/\*.*?\*\//', $line)) {
      $line = preg_replace('/\/\*.*?\*\//', '', $line);  // remove comments
    }
    // skip empty lines
    if (preg_match('/^\s*$/', $line) or strcmp($line, '')==0) {
      continue;
    }
    // Find SQL for new objects
    if (preg_match('/^\s*CREATE\s+TABLE/i', $line) and !$in_string) {
      $stack[] = 'table';
      $line = preg_replace("/public./", "chado.", $line);
    }
    if (preg_match('/^\s*ALTER\s+TABLE/i', $line) and !$in_string) {
      $stack[] = 'alter table';
      $line = preg_replace("/public./", "chado.", $line);
    }
    if (preg_match('/^\s*SET/i', $line) and !$in_string) {
      $stack[] = 'set';
    }
    if (preg_match('/^\s*CREATE\s+SCHEMA/i', $line) and !$in_string) {
      $stack[] = 'schema';
    }
    if (preg_match('/^\s*CREATE\s+SEQUENCE/i', $line) and !$in_string) {
      $stack[] = 'sequence';
      $line = preg_replace("/public./", "chado.", $line);
    }
    if (preg_match('/^\s*CREATE\s+(?:OR\s+REPLACE\s+)*VIEW/i', $line) and !$in_string) {
      $stack[] = 'view';
      $line = preg_replace("/public./", "chado.", $line);
    }
    if (preg_match('/^\s*COMMENT/i', $line) and !$in_string and sizeof($stack)==0) {
      $stack[] = 'comment';
      $line = preg_replace("/public./", "chado.", $line);
    }
    if (preg_match('/^\s*CREATE\s+(?:OR\s+REPLACE\s+)*FUNCTION/i', $line) and !$in_string) {
      $stack[] = 'function';
      $line = preg_replace("/public./", "chado.", $line);
    }
    if (preg_match('/^\s*CREATE\s+INDEX/i', $line) and !$in_string) {
      $stack[] = 'index';
    }
    if (preg_match('/^\s*INSERT\s+INTO/i', $line) and !$in_string) {
      $stack[] = 'insert';
      $line = preg_replace("/public./", "chado.", $line);
    }
    if (preg_match('/^\s*CREATE\s+TYPE/i', $line) and !$in_string) {
      $stack[] = 'type';
    }
    if (preg_match('/^\s*GRANT/i', $line) and !$in_string) {
      $stack[] = 'grant';
    }
    if (preg_match('/^\s*CREATE\s+AGGREGATE/i', $line) and !$in_string) {
      $stack[] = 'aggregate';
    }

    // determine if we are in a string that spans a line
    $matches = preg_match_all("/[']/i", $line, $temp);
    $in_string = $in_string - ($matches % 2);
    $in_string = abs($in_string);

    // if we've reached the end of an object the pop the stack
    if (strcmp($stack[sizeof($stack)-1], 'table') == 0 and preg_match('/\);\s*$/', $line)) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'alter table') == 0 and preg_match('/;\s*$/', $line) and !$in_string) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'set') == 0 and preg_match('/;\s*$/', $line) and !$in_string) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'schema') == 0 and preg_match('/;\s*$/', $line) and !$in_string) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'sequence') == 0 and preg_match('/;\s*$/', $line) and !$in_string) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'view') == 0 and preg_match('/;\s*$/', $line) and !$in_string) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'comment') == 0 and preg_match('/;\s*$/', $line) and !$in_string) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'function') == 0 and preg_match("/LANGUAGE.*?;\s+$/i", $line)) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'index') == 0 and preg_match('/;\s*$/', $line) and !$in_string) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'insert') == 0 and preg_match('/\);\s*$/', $line)) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'type') == 0 and preg_match('/\);\s*$/', $line)) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'grant') == 0 and preg_match('/;\s*$/', $line) and !$in_string) {
      $type = array_pop($stack);
    }
    if (strcmp($stack[sizeof($stack)-1], 'aggregate') == 0 and preg_match('/\);\s*$/', $line)) {
      $type = array_pop($stack);
    }
    // if we're in a recognized SQL statement then let's keep track of lines
    if ($type or sizeof($stack) > 0) {
      $query .= "$line";
    }
    else {
      print "UNHANDLED $i, $in_string: $line";
      return tripal_core_chado_install_done();
    }
    if (preg_match_all("/\n/", $query, $temp) > 100) {
      print "SQL query is too long.  Terminating:\n$query\n";
      return tripal_core_chado_install_done();
    }
    if ($type and sizeof($stack) == 0) {
      print "Adding $type: line $i\n";
      // rewrite the set serach_path to make 'public' be 'chado'
      if (strcmp($type, 'set')==0) {
        $query = preg_replace("/public/m", "chado", $query);
      }
      $result = pg_query($active_db, $query);
      if (!$result) {
        $error  = pg_last_error();
        print "Installation failed:\nSQL $i, $in_string: $query\n$error\n";
        pg_query($active_db, "set search_path to public,chado");
        return tripal_core_chado_install_done();
      }
      $query = '';
    }
  }
  print "Installation Complete!\n";
  tripal_core_chado_install_done();
}

/**
 * Finish the Chado Schema Installation
 *
 * @ingroup tripal_core
 */
function tripal_core_chado_install_done() {

  // return the search path to normal
  global $active_db;
  pg_query($active_db, "set search_path to public,chado");

}
