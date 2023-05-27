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


/**
 * A form for searching teams and cohorts.
 *
 * @copyright 2022 Jennifer Aube <jennifer.aube@civicactions.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @package   local_stargroup
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once($CFG->dirroot.'/lib/formslib.php');

/**
 * Search filters form class
 *
 * @copyright 2022 Jennifer Aube <jennifer.aube@civicactions.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @package   local_stargroup
 */
class search_cohort_filters_form extends moodleform {

    /**
     * Form definition
     */
    function definition () {
        global $USER, $CFG, $DB;
        $starcourse = $DB->get_record('course', ['shortname'=>'STAR']);
        $courseid = $starcourse->id;
        $coursecontext = context_course::instance($courseid);

        $mform =& $this->_form;
        $cohortnames = [];
        $cohorts = $DB->get_records('groupings', ['courseid'=>$courseid]);
        foreach($cohorts as $cohort) {
            $cohortnames[$cohort->id] = $cohort->name;
        }
        $options = array(
            'multiple' => true,
        );
        $mform->addElement('autocomplete', 'cohortslist', 'Filter by cohort:', $cohortnames, $options);

        $teamnames = [];
        $teams = $DB->get_records('groups', ['courseid'=>$courseid]);
        foreach($teams as $team) {
            $teamnames[$team->id] = $team->name;
        }
        $options = array(
            'multiple' => true,
        );
        $mform->addElement('autocomplete', 'teamslist', 'Filter by team:', $teamnames, $options);
        $this->add_action_buttons(true, 'Search');
    }

    /**
     * Form validation
     *
     * @param array $data
     * @param array $files
     * @return array $errors An array of validataion errors for the form.
     */
    function validation($data, $files) {

    }

}
