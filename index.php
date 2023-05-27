<?php


/**
 * A report to display the listing of all trainers showing their group and cohort(grouping)
 *
 * @package    report
 * @subpackage stardashboard
 * @copyright  2021 Jennifer Aube <jennifer.aube@civicactions.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
global $USER, $DB, $CFG, $PAGE, $COURSE, $OUTPUT;

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once('search_cohort_filters.php');

$searchquerycohort  = optional_param('searchcohort', '', PARAM_RAW);
$searchqueryteam  = optional_param('searchteam', '', PARAM_RAW);
$starcourse = $DB->get_record('course', ['shortname'=>'STAR']);
$starcourseid = $starcourse->id;
$courseid   = optional_param('courseid', $starcourseid, PARAM_INT);
$context = context_course::instance($courseid);
require_login($courseid);
$participantuser = $DB->get_records('role_assignments', array('userid' => $USER->id));
$roleid = $groupingid = $groupid = '';

foreach($participantuser as $puser) {
    $is_trainer = $is_student = $is_manager = false;
    // Auto enrol creates multiple rows with same roleid
    $roleid = $puser->roleid;
    $roles = $DB->get_record('role', array('id' => $roleid));
    if ($roles->name == 'Student' || $roles->name == 'State Lead' || $roles->name == 'Non-editing teacher') {
        $is_student = true;
        $groupmemberdb = $DB->get_records('groups_members', array('userid' => $USER->id));
        foreach($groupmemberdb as $group) {
            // Show participant last group they are in, if participant in many groups
            $groupid = $group->groupid;
        }
    } elseif ($roles->name == 'Teacher') {
        $is_trainer = true;
        $groupmemberdb = $DB->get_records('local_groupings_members', array('userid' => $USER->id));
        foreach($groupmemberdb as $gm) {
            $groupingid = $gm->groupingid;
        }
    } else if($roles->name == 'Registrar') {
        $is_manager = true;
    }
}
if($is_student && !$is_manager) {
    redirect('/report/stardashboard/groups.php?userid=' . $USER->id . '&groupid=' . $groupid . '&courseid=' . $courseid);
}


$search = new search_cohort_filters_form();
$noresults = false;
$groupings = [];
if($search != '') {
    if($search->is_cancelled()) {
        // Cancelled forms redirect to the course main page.
        $url = new moodle_url("/report/stardashboard/index.php?courseid=" . $courseid);
        redirect($url);
    } else if ($fromform = $search->get_data()){
        $cohortlisted = $teamlisted = false;
        foreach($fromform as $key => $value) {
            if($key == 'teamslist' && !empty($value)) {
                foreach($value as $team) {
                    $group = $DB->get_record('groups', ['id'=>$team] );
                    $groups[$team] = $group;
                }
                $teamlisted = true;
            }
            if($key == 'cohortslist' && !empty($value) && !$teamlisted) {
                foreach($value as $cohort) {
                    $grouping = $DB->get_record('groupings', ['id'=>$cohort]);
                    $groupings[$cohort] = $grouping;
                }
                $cohortlisted = true;
            }
            if($cohortlisted & $teamlisted) {
                $oldgroupings = $groupings;
                $groupings = [];
                foreach($groups as $teamselected) {
                    foreach($oldgroupings as $og) {
                        $groupinggroupdb = $DB->get_record('groupings_groups', ['groupid' => $teamselected->id, 'groupingid'=>$og->id]);
                        if(!empty($groupinggroupdb)) {
                            $gfg = $DB->get_record('groupings', ['id' => $groupinggroupdb->groupingid]);
                            if(!empty($gfg)) {
                                $groupings[$og->id] = $gfg;
                            }
                        }
                    }
                    if(empty($groupings)){
                        $noresults = true;
                    }
                }
                $cohortlisted = $teamlisted = false;
            }
        }
    } else {
        $groupings = $DB->get_records('groupings', ['courseid' => $courseid], 'name');
    }
}
if(isset($groups) && !$groupings && !$noresults) {
    foreach($groups as $searchgroups) {
        $groupinggroup = $DB->get_records('groupings_groups', ['groupid' => $searchgroups->id]);
        foreach($groupinggroup as $grouping) {
            $groupingsdb = $DB->get_record('groupings', ['id' => $grouping->groupingid]);
            $groupings[$grouping->groupingid] = $groupingsdb;
        }
    }
}
$PAGE->set_url('/report/stardashboard/index.php');
$PAGE->set_context(context_system::instance());
//$PAGE->set_pagelayout('starcourse');
$PAGE->set_heading('Student Achievement in Reading');
$PAGE->set_title('STAR');
// Breadcrumbs
$PAGE->navbar->add('My courses');
$PAGE->navbar->add('STAR', new moodle_url('/course/view.php', array('id' => $courseid)));

echo $OUTPUT->header();
// Filters

$params = array();
$params['courseid'] = $courseid;

if ($searchquerycohort != '') {
    $params['searchcohort'] = $searchquerycohort;
}
if ($searchqueryteam != '') {
    $params['searchteam'] = $searchqueryteam;
}
$baseurl = new moodle_url('/local/stargroup/index.php', $params);
$search->display();
if($noresults) {
    echo '<p>There are results for the team(s) you selected in the cohort(s) you selected.</p>';
} else {

echo $OUTPUT->box_start();
echo '<table id="star-cohort-membership" class="table generaltable flexible boxaligncenter">
        <thead>
            <tr>
                <th scope="row" class="rowheader">Cohort</th>
                <th>My team(s)</th>
                <th>Trainer(s)</th>
            </tr>
       </thead>
       <tbody>';

//$cohorts = $DB->get_records('groupings', array('courseid' => $courseid));
$cohortsarray = array();
foreach ($groupings as $cohort) {
    $cohortarr = $trainerarr = $teamarr = array();
    $groupingid = $cohort->id;
    $cohortarr['cohortid'] = $groupingid;
    $cohortarr['cohortname'] = $cohort->name;
    $cohorttrainers = $DB->get_records('local_groupings_members', array('groupingid' => $groupingid));
    foreach($cohorttrainers as $trainers) {
        $trainer = $DB->get_record('user', array('id' => $trainers->userid));
        $trainerarr[$trainers->userid] = $trainer->username;
        $cohortarr['trainers'] = $trainerarr;
    }

    $cohortteams = $DB->get_records('groupings_groups', array('groupingid' => $groupingid));
    foreach($cohortteams as $teams) {
        $team = $DB->get_record('groups', array('id' => $teams->groupid));
        $teamarr[$teams->groupid] = $team->name;
    }
    $cohortarr['teams'] = $teamarr;
    array_push($cohortsarray, $cohortarr);
}

if ($is_trainer) {
    $carray = [];
    foreach($cohortsarray as $id => $row) {
        if (!empty($row['trainers'])) {
            foreach ($row['trainers'] as $key2 => $data2) {
                if ($key2 == $USER->id) {
                    array_push($carray, $cohortsarray[$id]);
                }
            }
        }
    }
    foreach($carray as $id => $row) {
        echo '<tr><td><a href="/report/stardashboard/groups.php?courseid='.$courseid.'&groupingid=' . $row['cohortid'] . '">' . $row['cohortname'] . '</a></td>';
        echo '<td>';
        foreach($row['teams'] as $teamid => $team) {
            echo '<a href="/report/stardashboard/groups.php?courseid='.$courseid.'&groupid=' . $teamid . '">' . $team . '</a>';
        }
        echo '</td>';
        echo '<td>';
        foreach($row['trainers'] as $trainerid => $trainer) {
            echo '<a href="/local/staruser/user.php?userid=' . $trainerid . '">' . $trainer . '</a>';
        }
        echo '</td>';
        echo '</tr>';

    }
} else {
    foreach($cohortsarray as $item => $row) {
        if(!empty($row['trainers'])) {
            echo '<tr><td><a href="/report/stardashboard/groups.php?courseid='.$courseid.'&groupingid=' . $row['cohortid'] . '">' . $row['cohortname'] . '</a></td>';
            echo '<td>';
            foreach($row['teams'] as $teamid => $team) {
                echo '<a href="/report/stardashboard/groups.php?courseid='.$courseid.'&groupid=' . $teamid . '">' . $team . '</a>';
            }
            echo '</td>';
            echo '<td>';
            foreach($row['trainers'] as $trainerid => $trainer) {
                echo '<a href="/local/staruser/user.php?userid=' . $trainerid . '">' . $trainer . '</a>';
            }
            echo '</td>';
            echo '</tr>';
        } else {
            echo '<tr><td><a href="/report/stardashboard/groups.php?courseid='.$courseid.'&groupingid=' . $row['cohortid'] . '">' . $row['cohortname'] . '</a></td>';
            echo '<td>';
            foreach($row['teams'] as $teamid => $team) {
                echo '<a href="/report/stardashboard/groups.php?courseid='.$courseid.'&groupid=' . $teamid . '">' . $team . '</a>';
            }
            echo '</td>';
            echo '<td>No trainer assigned.</td>';
            echo '</tr>';
        }

    }
}

echo '</tbody></table>';
    echo $OUTPUT->box_end();
}

echo $OUTPUT->footer();
