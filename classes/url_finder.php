<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_httpsreplace;

defined('MOODLE_INTERNAL') || die();

/**
 * Examines DB for non-https src or data links that will cause trouble
 * when embedded in HTTPS sites.
 *
 * @package tool_httpsreplace
 * @copyright Copyright (c) 2016 Blackboard Inc. (http://www.blackboard.com)
 */
class url_finder {

    /**
     * Array of objects defining the exceptions for HTTPS swap
     *
     * @var stdClass
     * @access private
     */
    private $exceptionsobj;

    public function http_link_stats() {
        return $this->process(false);
    }

    public function upgrade_http_links() {
        return $this->process(true);
    }

    /**
     * Originally forked from core function db_search().
     */
    private function process($replacing = false) {
        global $DB, $CFG;
        require_once($CFG->libdir.'/filelib.php');
        self::load_exceptions();
        $httpurls  = "(src|data)\ *=\ *[\\\"\']http://";

        // TODO: block_instances have HTML content as base64, need to decode then
        // search, currently just skipped.
        $skiptables = array(
            'block_instances',
            'config',
            'config_log',
            'config_plugins',
            'events_queue',
            'files',
            'filter_config',
            'grade_grades_history',
            'grade_items_history',
            'log',
            'logstore_standard_log',
            'repository_instance_config',
            'sessions',
            'upgrade_log',
            'grade_categories_history',
            '',
        );

        // Turn off time limits.
        \core_php_time_limit::raise();
        if (!$tables = $DB->get_tables() ) {    // No tables yet at all.
            return false;
        }

        $urls = array();
        $texttypes = array (
            'text',
            'mediumtext',
            'longtext',
            'varchar',
        );

        foreach ($tables as $table) {
            if (in_array($table, $skiptables)) {
                continue;
            }
            if ($columns = $DB->get_columns($table)) {
                $regexp = $DB->sql_regex();
                foreach ($columns as $column) {

                    if (in_array($column->type, $texttypes)) {
                        $columnname = $column->name;
                        $select = "$columnname $regexp ?";
                        $rs = $DB->get_recordset_select($table, $select, [$httpurls]);

                        $found = array();
                        foreach ($rs as $record) {
                            // Regex to match src=http://etc. and data=http://etc.urls.
                            // Standard warning on expecting regex to perfectly parse HTML
                            // read http://stackoverflow.com/a/1732454 for more info.
                            $regex = '#(src|data)\ *=\ *[\'\"]http://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))[\'\"]#';
                            preg_match_all($regex, $record->$columnname, $match);
                            foreach ($match[0] as $url) {
                                if (strpos($url, $CFG->wwwroot) !== false) {
                                    continue;
                                }
                                if ($replacing) {
                                    $url = substr($url, strpos($url, 'http'), -1);
                                    $host = parse_url($url, PHP_URL_HOST);
                                    $found[] = $host;
                                } else {
                                    $entry["table"] = $table;
                                    $entry["columnname"] = $columnname;
                                    $entry["url"] = str_replace(array("'", '"'), "", substr($url, ((int) strpos($url, "=") + 1) ));
                                    $entry["host"] = parse_url($entry["url"], PHP_URL_HOST);
                                    $entry["raw"] = $record->$columnname;
                                    $entry["ssl"] = '';
                                    $urls[] = $entry;
                                }
                            }
                        }
                        $rs->close();

                        if ($replacing) {
                            $found = array_unique($found);
                            foreach ($found as $domain) {
                                $this->domain_swap_exceptions_file($table, $column, $domain);
                            }
                        }
                    }
                }
            }
        }

        if ($replacing) {
            rebuild_course_cache(0, true);
            purge_all_caches();
            return true;
        }

        $domains = array_map(function ($i) {
            return $i['host'];
        }, $urls);

        $uniquedomains = array_unique($domains);

        $sslfailures = array();
        $knownsupported = array(
            'amazon.com',
            'www.amazon.com',
            'dropbox.com',
            'www.dropbox.com',
            'cdnapi.kaltura.com',
            'fe8be92ac963979368eca.r38.cf1.rackcdn.com', // Not actually a real domain, but used for testing.
        );

        foreach ($uniquedomains as $domain) {
            if (in_array($domain, $knownsupported)) {
                continue;
            }
            $url = "https://$domain/";
            $curl = new \curl();
            $curl->head($url);
            $info = $curl->get_info();
            if (empty($info['http_code']) or ($info['http_code'] >= 400)) {
                $sslfailures[] = $domain;
            }
        }

        $results = array();
        foreach ($urls as $url) {
            $host = $url['host'];
            foreach ($sslfailures as $badhost) {
                if ($host == $badhost) {
                    if (!isset($results[$host])) {
                        $results[$host] = 1;
                    } else {
                        $results[$host]++;
                    }
                }
            }
        }
        return $results;
    }

    /**
     * swap the domain depending on exceptions object
     * @param string $table
     * @param stdClass $column
     * @param string $domain
     */
    private function domain_swap_exceptions_file($table, $column, $domain) {
        global $DB;
        $search = "http://" . $domain;
        $replace = "https://" . $domain;
        foreach ($this->exceptionsobj as $exception) {
            $target = "/" . $exception->target . "$/";
            if (preg_match($target, $domain)) {
                $pattern = "/" . $exception->rule->from . "$/";
                $replacement = $exception->rule->to;
                $replace = "https://" . preg_replace($pattern, $replacement, $domain);
            }
        }
        $DB->set_debug(true);
        // Note, this search is case sensitive.
        $DB->replace_all_text($table, $column, $search, $replace);
        $DB->set_debug(false);
    }

    /**
     * Load exceptions from exceptions.json
     * Exceptions are the rulesets for swapping from http to https
     */
    private function load_exceptions() {
        global $CFG;
        $filename = "exceptions.json";
        $fileurl = $CFG->dirroot . '/admin/tool/httpsreplace/';
        $exceptionsdata = self::load_data($fileurl, $filename);
        $this->exceptionsobj = json_decode($exceptionsdata);
    }

    /**
     * @param  string $rpath
     * @param  string $name
     * @return null|string
     */
    private static function load_data($rpath,  $name) {
        $result = null;
        $repath = realpath($rpath.$name);
        if ($repath !== false) {
            $lresult = file_get_contents($repath);
            if ($lresult !== false) {
                $result = $lresult;
            }
        }
        return $result;
    }
}
