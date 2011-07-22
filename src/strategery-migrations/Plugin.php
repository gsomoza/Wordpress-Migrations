<?php

/*
 * Plugin Name: Strategery Migrations
 * Plugin URI: http://usestrategery.com
 * Description: Allows for easier migrations to the database.
 * Author: Gabriel Somoza (me@gabrielsomoza.com)
 * Version: 0.1
 * Author URI: http://gabrielsomoza.com
 * Compatibility: 1.3.1+
 */

if (!defined('ST_MIGRATIONS_VERSION'))
    define('ST_MIGRATIONS_VERSION', '0.1');

if (!defined('ST_MIGRATIONS_BASE_PATH'))
    define('ST_MIGRATIONS_BASE_PATH', realpath(dirname(__FILE__)));

if (!defined('ST_MIGRATIONS_LIB'))
    define('ST_MIGRATIONS_LIB', ST_MIGRATIONS_BASE_PATH . '/lib');

if (!defined('ST_MIGRATIONS_TEMPLATES'))
    define('ST_MIGRATIONS_TEMPLATES', ST_MIGRATIONS_BASE_PATH . '/templates');

if (!defined('ST_MIGRATIONS_HELPERS_PATH'))
    define('ST_MIGRATIONS_HELPERS_PATH', ST_MIGRATIONS_LIB . '/Helpers');

if (!defined('ST_MIGRATIONS_MODELS_PATH'))
    define('ST_MIGRATIONS_MODELS_PATH', ST_MIGRATIONS_LIB . '/Models');

if (!defined('ST_MIGRATIONS_DIR'))
    define('ST_MIGRATIONS_DIR', PLUGINDIR . '/strategery-migrations/migrations');

if (!defined('ST_MIGRATIONS_URL'))
    define('ST_MIGRATIONS_URL', plugins_url('strategery-migrations'));

require_once ST_MIGRATIONS_LIB . '/Exception.php';
require_once ST_MIGRATIONS_LIB . '/Core.php';

class Strategery_Migrations_Plugin extends Strategery_Migrations_Core {
    const OPTION_MIGRATION_STATE = 'migration_state';
    const FILENAME_REGEX = '/^(\d{14})-([A-Za-z][A-Za-z0-9_]*)$/';
    const QUERY_TRIGGER = 'migrate';
    const QUERY_ACTION = 'action';
    const QUERY_METHOD = 'method';
    const QUERY_NAME = 'name';
    const QUERY_TEMPLATE = 'template';
    const QUERY_ID = 'id';
    const METHOD_UP = 'up';
    const METHOD_DOWN = 'down';

    protected $migrateMethods;
    
    public function newAction() {
        $name = get_query_var(self::QUERY_NAME);
        if(empty($name)) {
            throw new Strategery_Migrations_Exception('No name specified.');
        }
        $template = get_query_var(self::QUERY_TEMPLATE);
        if(empty($template)) {
            $template = 'default';
        }
        $newName = $this->getNewMigrationName($name);
        $newClass = $this->underscoreToClassName($name);
        $template = $this->getMigrationTemplate($newClass, $template);
        $this->log('Creating migration ' . $newName);
        $path = ST_MIGRATIONS_DIR . '/' . $newName;
        if(file_put_contents($path, $template, LOCK_EX)) {
            $this->log('File created at: ' . $path);
        } else {
            $this->log('Unable to create file at: ' . $path);
        }
    }
    
    protected function getMigrationTemplate($class, $template = 'default') {
        $template = file_get_contents(ST_MIGRATIONS_TEMPLATES . '/' . $template . '.txt');
        return str_replace('{{class_name}}', $class, $template);
    }
    
    protected function getTimestamp() {
        return date('YmdHis');
    }
    
    public function getNewMigrationName($method) {
        return $this->getTimestamp() . '-' . $method . '.php';
    }

    public function migrateAction() {
        $oldBlogId = get_current_blog_id();
        $blogId = get_query_var(self::QUERY_ID);
        if(empty($blogId))
            $blogId = $oldBlogId;
        $this->migrate($blogId);
        if($oldBlogId != $blogId)
            switch_to_blog($oldBlogId);
    }

    public function migrateAllAction() {
        $db = $this->db();
        $blogs = $db->get_col($db->prepare("SELECT blog_id FROM $db->blogs"));
        foreach ($blogs as $blogId) {
            $this->migrate($blogId);
        }
    }

    public function runAction() {
        if (!$id = intval(get_query_var('id'))) {
            throw new Strategery_Migrations_Exception('No migration ID specified.');
        }
        $method = get_query_var('method') ? get_query_var('method') : self::METHOD_UP;
        foreach (glob(ST_MIGRATIONS_DIR . '/*.php') as $filename) {
            if (strpos($filename, $id) != 0)
                continue;
            $this->run($filename, $method);
        }
        $this->log('Finished' . "\n");
    }

    protected function migrate($blogId) {
        switch_to_blog($blogId);
        $this->log('Migrating Blog ' . $blogId . ' ', false);
        $oldLatest = $latest = get_blog_option($blogId, self::OPTION_MIGRATION_STATE, 0);
        $this->log('- Latest: ' . $latest);
        foreach (glob(ST_MIGRATIONS_DIR . '/*.php') as $filename) {
            $id = $this->getMigrationID($filename);
            if ($id <= $latest)
                continue;
            $this->run($filename);
            $latest = $id;
        }
        if ($latest != $oldLatest) {
            update_blog_option($blogId, self::OPTION_MIGRATION_STATE, $latest);
            $this->log('New Latest: ' . $latest . "\n");
        } else {
            $this->log('Already up to date' . "\n");
        }
    }

    protected function run($filename, $method = self::METHOD_UP) {
        if (!in_array($method, $this->migrateMethods)) {
            throw new Strategery_Migrations_Exception('Method should be either "up" or "down"');
        }
        if ($class = $this->getMigrationClassName($filename)) {
            try {
                $this->log('>>>> Executing "' . basename($filename) . '"');
                require_once $filename;
                $migration = new $class();
                $migration->$method();
                $this->log('>>>> Migration Finished');
            } catch (Strategery_Migrations_Exception $e) {
                $this->log('>> Error during migration: ' . $e->getMessage());
            }
        } else {
            throw new Strategery_Migrations_Exception('Could not infer classname from migration file "' . $filename . '"');
        }
    }

    public function __construct() {
        $this->migrateMethods = array(self::METHOD_UP, self::METHOD_DOWN);

        $this->addFilter('query_vars');
        $this->addAction('parse_query');
    }

    public function filterQueryVars($vars) {
        $vars[] = self::QUERY_TRIGGER;
        $vars[] = self::QUERY_ACTION;
        $vars[] = self::QUERY_ID;
        $vars[] = self::QUERY_METHOD;
        $vars[] = self::QUERY_NAME;
        $vars[] = self::QUERY_TEMPLATE;
        return $vars;
    }

    public function actionParseQuery() {
        if ($action = $this->getQueryActionName()) {
            @header('Content-Type: text-plain');
            try {
                require_once ST_MIGRATIONS_LIB . '/Migration.php';
                $this->log('[Action "' . $this->getQueryAction() . '" | ' . date('Y-m-d H:i:s') . ']');
                $this->$action();
            } catch (Strategery_Migrations_Exception $e) {
                global $wp_query;
                $message = 'An error occured while executing action "' . $this->getQueryAction() . '":' . "\n\n";
                $message .= $e->getMessage() . "\n\n-------\n\n";
                $message .= 'Arguments = ';
                $message .= print_r($wp_query->query_vars, true);
                $this->log($message);
            }
            exit;
        }
    }

    protected function addAction($tag, $function = null, $priority = 10, $accepted_args = 1) {
        if (!$function)
            $function = $this->underscoreToCamelCase($tag, 'action');
        add_action($tag, array(&$this, $function), $priority, $accepted_args);
    }

    protected function addFilter($tag, $function = null, $priority = 10, $accepted_args = 1) {
        if (!$function)
            $function = $this->underscoreToCamelCase($tag, 'filter');
        add_filter($tag, array(&$this, $function), $priority, $accepted_args);
    }

    protected function getQueryAction() {
        return get_query_var(self::QUERY_ACTION);
    }

    protected function getQueryActionName() {
        if (intval(get_query_var(self::QUERY_TRIGGER)) == 1) {
            $action = $this->getQueryAction();
            $name = $this->underscoreToCamelCase($action);
            $name = lcfirst($name) . 'Action';
            return method_exists($this, $name) ? $name : FALSE;
        }
        return FALSE;
    }

    /**
     * Returns the class name inferred from the migration file name.
     * Format: ########-class_name.php  
     * 
     * @param string $path Path to the file including file name and extension.
     */
    protected function getMigrationClassName($path) {
        if ($matches = $this->parseMigrationFilename($path)) {
            return $this->underscoreToClassName($matches[2]);
        }
        return false;
    }

    protected function getMigrationID($path) {
        if ($matches = $this->parseMigrationFilename($path)) {
            return (float) $matches[1];
        }
        return false;
    }

    protected function parseMigrationFilename($path) {
        $basename = basename($path, '.php');
        $matches = array();
        return preg_match(self::FILENAME_REGEX, $basename, $matches) ? $matches : false;
    }

}

global $stMigrations;
$stMigrations = new Strategery_Migrations_Plugin();
