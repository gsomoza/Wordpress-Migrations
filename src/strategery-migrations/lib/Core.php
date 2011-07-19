<?php

class Strategery_Migrations_Core {

    static protected $helpers = array();

    /**
     * Returns $wpdb
     * @return wpdb $wpdb
     */
    public function db() {
        global $wpdb;
        return $wpdb;
    }

    protected function log($message, $nl = true) {
        echo $message;
        if ($nl)
            echo "\n";
    }

    protected function debug($object) {
        die('<pre>' . print_r($object, true));
    }

    protected function underscoreToCamelCase($underscore, $prepend = '') {
        $result = str_replace('_', ' ', $underscore);
        $result = ucwords($result);
        $result = str_replace(' ', '', $result);
        if (empty($prepend))
            $result = lcfirst($result);
        return $prepend . $result;
    }

    protected function underscoreToClassName($underscore) {
        $result = $this->underscoreToCamelCase($underscore);
        return ucfirst($result);
    }

    protected function helper($type) {
        if (!isset($this->helpers[$type])) {
            $parts = explode('/', $type);
            $underscore = array_pop($parts);
            $class = $this->underscoreToClassName($underscore);
            $parts[] = $class;
            $className = 'Strategery_Migrations_Helper_' . $class;
            if (!class_exists($className)) {
                $path = path_join(ST_MIGRATIONS_HELPERS_PATH, implode('/', $parts) . '.php');
                if ($realPath = realpath($path)) {
                    require_once ST_MIGRATIONS_LIB . '/Helper.php';
                    require_once $realPath;
                } else {
                    throw new Strategery_Migrations_Exception('Could not find helper for type "' . $type . ' at "' . $path . '"');
                }
            }
            $this->helpers[$type] = new $className();
        }
        return $this->helpers[$type];
    }

    protected function getModel($type, $data) {
        $parts = explode('/', $type);
        $underscore = array_pop($parts);
        $class = $this->underscoreToClassName($underscore);
        $className = 'Strategery_Migrations_Model_' . $class;
        $parts[] = $class;
        if (!class_exists($className)) {
            $path = path_join(ST_MIGRATIONS_MODELS_PATH, implode('/', $parts) . '.php');
            if ($realPath = realpath($path)) {
                require_once ST_MIGRATIONS_LIB . '/Model.php';
                require_once $realPath;
            } else {
                throw new Strategery_Migrations_Exception('Could not find model "' . $type . '" at ' . $path);
            }
        }
        return new $className($data);
    }

}