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
 * Course completion progress report
 *
 * @package    report
 * @subpackage stardashboard
 * @copyright  Jennifer Aube <jennifer.aube@civicactions.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
global $CFG, $PAGE, $OUTPUT, $DB, $USER;
require_once(__DIR__.'/../../config.php');
require_once("{$CFG->libdir}/completionlib.php");
require_once("{$CFG->libdir}/grouplib.php");
require_once($CFG->dirroot . '/lib/enrollib.php');


/**
 * Configuration
 */
define('COMPLETION_REPORT_PAGE',        50);
define('COMPLETION_REPORT_COL_TITLES',  true);

/*
 * Setup page, check permissions
 */

// Get course
$courseid = required_param('courseid', PARAM_INT);
$groupingid = optional_param('groupingid', false, PARAM_INT);
$groupid = optional_param('groupid', false, PARAM_INT);

$context = context_course::instance($courseid);

$url = new moodle_url('/report/stardashboard/groups.php', array('courseid'=>$courseid));
$PAGE->set_url($url);
$PAGE->requires->js('/report/stardashboard/assets/stardashboard.js');

// Paging
$start   = optional_param('start', 0, PARAM_INT);
$sifirst = optional_param('sifirst', 'all', PARAM_NOTAGS);
$silast  = optional_param('silast', 'all', PARAM_NOTAGS);

// Check permissions
require_login($courseid);

/**
 * Load data
 */

// Retrieve course_module data for all modules in the course
$modinfo = [];
$subcourseids = [];
$courses = $DB->get_record_select('course', 'id = ?', array($courseid));
$sectioncompletion = [];
$i = 0;


$strcompletion = get_string('coursecompletion');
$groupname = '';
$PAGE->set_title('Dashboard');
if($groupingid) {
    $grouping = $DB->get_record_select('groupings', "id = ?", array($groupingid));
    $groupname = $grouping->name;
} else if ($groupid) {
    $group = $DB->get_record_select('groups', "id = ?", array($groupid));
    $groupname = $group->name;
}
$PAGE->set_heading('Student Achievement in Reading');


$prcompletion = false;
echo $OUTPUT->header();
echo '<h2>' . $groupname. '</h2>';

$modinfo = get_fast_modinfo($courseid);
$context = context_course::instance($courseid);

$completion = new completion_info($courses);
if (!$completion->has_criteria()) {
    print_error('nocriteriaset', 'completion', $CFG->wwwroot . '/course/report.php?id=' . $courseid);
}
$criteria = array();
foreach ($completion->get_criteria(COMPLETION_CRITERIA_TYPE_COURSE) as $criterion) {
    $criteria[] = $criterion;
}

foreach ($completion->get_criteria(COMPLETION_CRITERIA_TYPE_ACTIVITY) as $criterion) {
    $criteria[] = $criterion;
}

foreach ($completion->get_criteria() as $criterion) {
    if (!in_array($criterion->criteriatype, array(
        COMPLETION_CRITERIA_TYPE_COURSE, COMPLETION_CRITERIA_TYPE_ACTIVITY))) {
        $criteria[] = $criterion;
    }
}

// Can logged in user mark users as complete?
// (if the logged in user has a role defined in the role criteria)
$allow_marking = false;
$allow_marking_criteria = null;


// Get role criteria
$rcriteria = $completion->get_criteria(COMPLETION_CRITERIA_TYPE_ROLE);

if (!empty($rcriteria)) {

    foreach ($rcriteria as $rcriterion) {
        $users = get_role_users($rcriterion->role, $context, true);
        // If logged in user has this role, allow marking complete
        if ($users && in_array($USER->id, array_keys($users))) {
            $allow_marking = true;
            $allow_marking_criteria = $rcriterion->id;
            break;
        }
    }
}
if ($sifirst !== 'all') {
    set_user_preference('ifirst', $sifirst);
}
if ($silast !== 'all') {
    set_user_preference('ilast', $silast);
}

if (!empty($USER->preference['ifirst'])) {
    $sifirst = $USER->preference['ifirst'];
} else {
    $sifirst = 'all';
}

if (!empty($USER->preference['ilast'])) {
    $silast = $USER->preference['ilast'];
} else {
    $silast = 'all';
}

// Generate where clause
$where = array();
$where_params = array();

if ($sifirst !== 'all') {
    $where[] = $DB->sql_like('u.firstname', ':sifirst', false, false);
    $where_params['sifirst'] = $sifirst . '%';
}
if ($silast !== 'all') {
    $where[] = $DB->sql_like('u.lastname', ':silast', false, false);
    $where_params['silast'] = $silast . '%';
}
//// Get user match count
$total = $completion->get_num_tracked_users(implode(' AND ', $where), $where_params, $groupid);
// Total user count
$grandtotal = $completion->get_num_tracked_users('', array(), $groupid);

// Get user data
$progress = array();

if ($total) {
    $progress = $completion->get_progress_all(
        implode(' AND ', $where),
        $where_params,
        $groupid
    );

} else {
    $groupmembers = $DB->get_records('groups_members', ['groupid'=>$groupid]);
    foreach ($groupmembers as $sl) {
        $roles = $DB->get_records('role_assignments', array('userid' => $sl->userid));
        foreach ($roles as $r) {
            $role = $DB->get_record('role', array('id' => $r->roleid));
            if ($role->name === 'State Lead') {
                $user = $DB->get_record('user', ['id' =>$sl->userid]);
                $progress[$sl->userid] = $user;
                array_push($progress, $user);
//                $participant = true;
            }
        }

    }

//    $progress = get_enrolled_users($context, 'mod/modulestar:view', $groupid);
}

if (!$grandtotal && empty($progress)) {
    echo $OUTPUT->container(get_string('err_nousers', 'completion'), 'errorbox errorboxcontent');
    echo $OUTPUT->footer();
    exit;
}

// Loop through each completion activity
$dashboardclass = '';
foreach ($criteria as $indexkey => $section) {

    $moduletitle = $section->get_title_detailed();
    $moduleid = explode('.', $moduletitle);
    // Create accordion sections to seperate the modules

    if ($moduleid[0] == '1' || $moduleid[0] == '14' || $moduletitle == '26. Program Action Plan Update') {
        print '<div class="accordion star-dashboard--accordion" id="studentdata-accordion"><div class="accordion-item" id="accordion">';
        print '<h3 class="usa-accordion__heading"><button class="usa-accordion__button" type="button" aria-expanded="true">';
        $sectionname = '';
        $sectionid = '';
        if ($moduleid[0] == '1') {
            $sectionname = 'Section 1: Diagnostic Assessment';
            $sectionid = 'section-1';
        }
        if ($moduleid[0] == '14') {
            $sectionname = 'Section 2: Teaching EBRI';
            $sectionid = 'section-2';
        }
        if ($moduletitle == '26. Program Action Plan Update') {
            $sectionname = 'Section 3: Putting it all Together';
            $sectionid = 'section-3';
        }
        echo $sectionname;
        print '<i class="fa fa-plus"></i><i class="fa fa-minus"></i></button></h3>';
        print '<div id="' . $sectionid . '" class="accordion-collapse usa-accordion__content usa-prose show"><div class="accordion-body">';
        print '<table id="completion-progress" class="table star-dashboard--table table-bordered generaltable flexible boxaligncenter
        completionreport" style="text-align: left; margin-top: 0;" cellpadding="5" border="1">';

        // Print criteria group names
        print PHP_EOL . '<thead id="star-dashboard--header">';

        $current_group = false;
        $col_count = 0;
        for ($i = 0; $i <= count($criteria); $i++) {

            if (isset($criteria[$i])) {
                $criterion = $criteria[$i];

                if ($current_group && $criterion->criteriatype == $current_group->criteriatype) {
                    ++$col_count;
                    continue;
                }
            }

            // Print header cell
            if ($col_count) {
                $has_agg = array(
                    COMPLETION_CRITERIA_TYPE_COURSE,
                    COMPLETION_CRITERIA_TYPE_ACTIVITY,
                    COMPLETION_CRITERIA_TYPE_ROLE,
                );
            }

            if (isset($criteria[$i])) {
                // Move to next criteria type
                $current_group = $criterion;
                $col_count = 1;
            }
        }

        // Get course aggregation
        $method = $completion->get_aggregation_method();
        print '<tr>';

        print '<td class="row-header" style="clear: both;" aria-label="Module titles"></td>';

        foreach ($progress as $user) {
            if (completion_can_view_data($user->id, $courses)) {
                $userurl = new moodle_url('/blocks/completionstatus/details.php', array('course' => $courseid, 'user' => $user->id));
            } else {
                $userurl = new moodle_url('/user/view.php', array('id' => $user->id, 'course' => $courseid));
            }
            // TODO Change this to point to the user profile Custom plugin staruser
            if($user->id == $USER->id) {
                $dashboardclass = 'current-user';
            }
            print '<th scope="col" class="' . $dashboardclass . '"><a href="/local/staruser/user.php?userid=' . $user->id . '">' .
                fullname($user, has_capability('moodle/site:viewfullnames', $context)) . '</a></th>';
        }

        print '</tr></thead>';

        echo '<tbody>';

    }
    // Generate icon details
    $iconlink = '';
    $crit = 0;

    // Print each row of section with a column per user
    $iconlink = $CFG->wwwroot . '/mod/' . $section->module . '/view.php?id=' . $section->moduleinstance;

    $modinfo = get_fast_modinfo($courseid);
    $modulenamearr = $modinfo->cms;
    $modulename = $modulenamearr[$section->moduleinstance]->name;
    $instructformid = 0;

    if ($modulename == 'Record Goals'
        || $modulename == 'Record Challenges'
        || strpos($modulename, 'Record')
        || strpos($modulename, 'Assessment Reflections')
        || $modulename == 'Individual Reflections'
        || $modulename == '23. Individual Reflections'
        || $modulename == 'Instructional Priorities and Groupings'
        || $modulename == 'Reflections and Goals'
        || $modulename == '30. Reflections and Goals'){
        // Do nothing
    }
    else {
        print '<tr>';
        print '<th scope="row">';

        if ($moduleid[0] == '5' || $moduleid[0] == '12' || $modulename == '26. Program Action Plan Update' || $moduleid[0] == '30') {
            print '<div class="rotated-text-container">';
            print ($iconlink ? '<a href="' . $iconlink . '" title="' . $modulename . '">' : '');
            print '<i class="fa fa-users" style="padding-right:3px"></i>' .
                $modulename . '</a></div>';
        } else if (strpos($modulename, ' Instruction Plan')) {
            print '<span style="padding-left:24px">Plan</span>';
            if ($moduleid[0] == 'Vocabulary Instruction Plan' || $modulename == '16. Vocabulary Instruction Plan') {
                $instructformid = 16;
            } elseif ($moduleid[0] == 'Fluency Instruction Plan' || $modulename == '18. Fluency Instruction Plan') {
                $instructformid = 18;
            } elseif ($moduleid[0] == 'Alphabetics Instruction Plan' || $modulename == '20. Alphabetics Instruction Plan') {
                $instructformid = 20;
            } elseif ($moduleid[0] == 'Comprehension Instruction Plan' || $modulename == '22. Comprehension Instruction Plan') {
                $instructformid = 22;
            }
        } else if (strpos($modulename, ' Instruction Assessment')) {
            print '<span style="padding-left:24px">Reflection</span>';
            $modinstance = $section->moduleinstance;
        } else if ($modulename == 'Implementing Diagnostic Assessment' || $modulename == '26. Implementing Diagnostic Assessment') {
            print '<span style="padding-left:24px">Goal 1: Implement diagnostic assessment</span>';
            $modinstance = $section->moduleinstance;
        } else if ($modulename == 'Making Systemic Changes to Sustain EBRI' || $modulename == '26. Making Systemic Changes to Sustain EBRI') {
            print '<span style="padding-left:24px">Goal 2: Make systemic changes</span>';
            $modinstance = $section->moduleinstance;
        } else if ($modulename == 'Teaching using Evidence-based Techniques' || $modulename == '26. Teaching using Evidence-based Techniques') {
            print '<span style="padding-left:24px">Goal 3: Teach using evidence-based techniques</span>';
            $modinstance = $section->moduleinstance;
        } else if ($modulename == 'Teaching with an Instructional Routine: Lesson Plan' || $modulename == '29. Teaching with an Instructional Routine: Lesson Plan') {
            print '<span style="padding-left:24px">Plan</span>';
            $modinstance = $section->moduleinstance;
            $instructformid = 29;
        } else if ($modulename == 'Create Lesson Plan Assessment' || $modulename == '29. Create Lesson Plan Assessment') {
            print '<span style="padding-left:24px">Assess</span>';
            $modinstance = $section->moduleinstance;
        } else if ($modulename == 'Coach Feedback' || $modulename == '29. Coach Feedback') {
            print '<span style="padding-left:24px">Coach feedback</span>';
            $modinstance = $section->moduleinstance;
        } else {
            print '<div class="rotated-text-container">';
            print ($iconlink ? '<a href="' . $iconlink . '" title="' . $modulename . '">' : '');
            print $modulename . '</a></div>';
        }

        print '</th></td>';
        if($moduletitle == '16. Vocabulary Plan and Reflection') {
            $type = 'Optional';
        }
        ///
        /// Display a row for each user
        $instanced = '';
        $criterion = '';
        foreach ($progress as $user) {
            $is_complete = '';
            $completiontype = '';
            $state = '';
            $dashboardclass = '';

            if($user->id == $USER->id) {
                $dashboardclass = 'current-user';
            }
            if ($moduleid[0] == '5' ||
                $moduleid[0] == '6' ||
                $moduleid[0] == '7' ||
                $moduleid[0] == '8' ||
                $moduleid[0] == '9' ||
                $moduleid[0] == '10' ||
                $moduleid[0] == '12' ||
                $moduleid[0] == '23' ||
                $moduletitle == '30. Next Steps') {
                if ($section->module == 'page') {
                    if ($instanced != $moduleid[0]) {
                        $key = ++$indexkey;
                        $modinstance = $criteria[$key]->moduleinstance;
                        $criterion = $criteria[$key];
                        $instanced = $moduleid[0];
                    }
                }

            } else if ($moduleid[0] == '1') {
                $modinstance = $section->moduleinstance;

            } else if ($moduleid[0] == '13' || $moduleid[0] == '25') {

                $completiontype = 'Optional';

            } else if ($moduleid[0] == '12a') {
                $instructformid = 12;
                $instructdb = $DB->get_record('star_instructional', array('userid' => $user->id, 'formid' => $instructformid));
                if(!$instructdb) {
                    $completiontype = 'Not started';
                } else {
                    if($instructdb->submission == 'Draft') {
                        $completiontype = '<a href="/local/star_instructional/view.php?formid=' . $instructformid . '&userid=' . $user->id . '">
                        <i class="fa fa-pause-circle"></i>' . $instructdb->submission . '</a>';
                    } else if($instructdb->status == 'Needs Action'){
                        $completiontype = '<a class="submission-needsaction" href="/local/star_instructional/view.php?formid=' . $instructformid . '&userid=' . $user->id . '">' . $instructdb->status . '</a>';
                    } else if($instructdb->status == 'Approved'){
                        $completiontype = '<a class="submission-approved" href="/local/star_instructional/view.php?formid=' . $instructformid . '&userid=' . $user->id . '">
                        <i class="fa fa-check-circle"></i>' . $instructdb->status . '</a>';
                    } else {
                        $completiontype = '<a href="/local/star_instructional/view.php?formid=' . $instructformid . '&userid=' . $user->id . '">
                        <i class="fa fa-hourglass"></i>' . $instructdb->status . '</a>';
                    }

                }

            } else if (strpos($modulename, 'Plan')) {
                if($modulename == 'Create Lesson Plan Assessment' || $modulename == '29. Create Lesson Plan Assessment') {
                    $criterion = $section;
                } else {
                    $instructdb = $DB->get_record('star_instructional', array('userid' => $user->id, 'formid' => $instructformid));
                    if(!$instructdb) {
                        $completiontype = 'Not started';
                    } else {
                        if($instructdb->submission == 'Draft') {
                            $completiontype = '<a href="/local/star_instructional/view.php?formid=' . $instructformid . '&userid=' . $user->id . '">
                                <i class="fa fa-pause-circle"></i>Draft</a>';
                        } else if($instructdb->status == 'Awaiting feedback') {
                            $completiontype = '<a href="/local/star_instructional/view.php?formid=' . $instructformid . '&userid=' . $user->id . '">
                                <i class="fa fa-hourglass"></i>' . $instructdb->status . '</a>';
                        } else if($instructdb->status == 'Approved') {
                            $completiontype = '<a class="submission-approved" href="/local/star_instructional/view.php?formid=' . $instructformid . '&userid=' . $user->id . '">
                                <i class="fa fa-check-circle"></i>' . $instructdb->status . '</a>';
                        } else if($instructdb->status == 'Needs Action') {
                            $completiontype = '<a class="submission-needsaction" href="/local/star_instructional/view.php?formid=' . $instructformid . '&userid=' . $user->id . '">
                                ' . $instructdb->status . '</a>';
                        } else {
                            $completiontype = 'Not started';
                        }

                    }
                }

            } else if($modulename == 'Coach Feedback' || $modulename == '29. Coach Feedback'){
                $coachform = $DB->get_record('local_star_coach', array('participantid' => $user->id));
                if(!$coachform) {
                    $completiontype = 'Not started';
                } else {
                    $completiontype = '<a href="/local/star_coach/view.php?userid=' . $user->id . '">View</a>';
                }
            } else {
                $criterion = $section;
            }
            if($criterion) {
                if ($criterion->module == 'modulestar') {

                    $assignmentid = '';
                    $assignment = $modinfo->instances['modulestar'];
                    foreach($assignment as $form) {
                        if($form->id == $modinstance) {
                            $assignmentid = $form->instance;
                        }
                    }
                    $assigndb = array();
                    if ($groupingid) {
                        $groupid = '';
                        $groupingsgroups = $DB->get_records('groupings_groups', array('groupingid' => $groupingid));
                        foreach($groupingsgroups as $groups) {
                            $usergroup = $DB->get_record('groups_members', array('userid' => $user->id, 'groupid' => $groups->groupid));
                            if($usergroup) {
                                $groupid = $usergroup->groupid;
                            }

                        }
                        $assigndb = $DB->get_records_select('modulestar_submission', 'assignment = ? AND userid = ? AND groupid = ?', array($assignmentid, '0', $groupid));

                    } else {
                        $assigndb = $DB->get_records_select('modulestar_submission', 'assignment = ? AND userid = ? AND groupid = ?', array($assignmentid, '0', $groupid));
                    }


                    if ($assigndb) {
                        foreach ($assigndb as $assign) {
                            if ($assign->status == 'submitted') {
                                $completiontype = '<a href="/mod/modulestar/view.php?action=grader&id=' . $modinstance . '&userid=' . $user->id . '">
                                    <i class="fa fa-hourglass"></i>Awaiting feedback</a>';
                            } else if ($assign->status == 'draft') {
                                $completiontype = '<a href="/mod/modulestar/view.php?action=grader&id=' . $modinstance . '&userid=' . $user->id . '">
                                    <i class="fa fa-pause-circle"></i>Draft</a>';
                            } else if ($assign->status == 'approved') {
                                $completiontype = '<a class="submission-approved" href="/mod/modulestar/view.php?action=grader&id=' . $modinstance . '&userid=' . $user->id . '">
                                    <i class="fa fa-check-circle"></i>Approved</a>';
                            } else if ($assign->status == 'actionreq') {
                                $completiontype = '<a class="submission-needsaction" href="/mod/modulestar/view.php?action=grader&id=' . $modinstance . '&userid=' . $user->id . '">Needs action</a>';
                            } else {
                                $completiontype = 'Not started';
                            }
                        }
                    } else {
                        $completiontype = 'Not started';
                    }
                }
                else if($criterion->module == 'edwiserform'){

                    $edwiserform = $DB->get_record('efb_submissionstatus', array('userid' => $user->id, 'modid' => $modinstance));
                    if(!empty($edwiserform)) {
                        $formid = $edwiserform->formid;
                        $submissionstring = $edwiserform->status;
                        if($submissionstring == 'Approved') {
                            $completiontype = '<a class="submission-approved" href="/local/edwiser_submission/index.php?formid=' . $formid . '&mod=' . $modinstance . '&userid=' . $user->id . '">
                                <i class="fa fa-check-circle"></i>' . $submissionstring . '</a>';
                        } else if($submissionstring == 'Needs Action') {
                            $completiontype = '<a class="submission-needsaction" href="/local/edwiser_submission/index.php?formid=' . $formid . '&mod=' . $modinstance . '&userid=' . $user->id . '">' . $submissionstring . '</a>';
                        } else if($submissionstring == 'Awaiting feedback') {
                            $completiontype = '<a href="/local/edwiser_submission/index.php?formid=' . $formid . '&mod=' . $modinstance . '&userid=' . $user->id . '">
                            <i class="fa fa-hourglass"></i>' . $submissionstring . '</a>';
                        } else {
                            $completiontype = '<a href="/local/edwiser_submission/index.php?formid=' . $formid . '&mod=' . $modinstance . '&userid=' . $user->id . '">
                                <i class="fa fa-pause-circle"></i>' . $submissionstring . '</a>';
                        }

                    } else {
                        $completiontype = 'Not started';
                    }

                }
            }
             if ($modulename == '1. Introduction') {
                $form = $DB->get_record('local_starchecklist', ['userid'=>$user->id]);
                if(!empty($form)) {
                    if($form->status == 'Approved') {
                        $completiontype = '<a class="submission-approved" href="/local/star_checklist/index.php?userid=' . $user->id . '">
                            <i class="fa fa-check-circle"></i>' . $form->status . '</a>';
                    } else if($form->status == 'Needs action') {
                        $completiontype = '<a class="submission-needsaction" href="/local/star_checklist/index.php?userid=' . $user->id . '">' . $form->status . '</a>';
                    } else {
                        $completiontype = '<a href="/local/star_checklist/index.php?userid=' . $user->id . '">
                            <i class="fa fa-hourglass"></i>' . $form->status . '</a>';
                    }

                } else {
                    $completiontype = 'Not started';
                }
            }

            // if mod edwiser form check if id exists in efb_form_data by user.
            // show awaiting feedback
            // else show incomplete
            if ($moduleid[0] == '11'
                || $moduleid[0] == '14'
                || $moduleid[0] == '15'
                || $modulename == '16. Vocabulary Plan and Reflection'
                || $moduleid[0] == '17'
                || $modulename == '18. Fluency Plan and Reflection'
                || $moduleid[0] == '19'
                || $modulename == '20. Alphabetics Plan and Reflection'
                || $moduleid[0] == '21'
                || $modulename == '22. Comprehension Plan and Reflection'
                || $moduleid[0] == '24'
                || $modulename == '26. Program Action Plan Update'
                || $moduleid[0] == '27'
                || $moduleid[0] == '28'
                || $modulename == '29. Teaching with an Instructional Routine'
                || $prcompletion
            ) {
                print '<td class="completion-progresscells ' . $dashboardclass . '">';
                print $completiontype = '';
                print '</td>';
            }
            else {
                print '<td class="completion-progresscells ' . $dashboardclass . '">';
                print $completiontype;
                print '</td>';
            }


        }
    }
    if($prcompletion) {
        $prcompletion = false;
    }
    $crit++;
    if ($moduleid[0] == '1') {
        unset($criteria[$crit]);
    }
    if ($moduleid[0] == '13' || $moduleid[0] == '25' || $modulename == '30. Next Steps') {
        if ($moduleid[0] == '13') {
            print '</tr>';
            echo '</tbody>';
            print '</table>';
            print '</div></div></div></div>';
        } else if ($moduleid[0] == '25') {
            print '</tr>';
            echo '</tbody>';
            print '</table>';
            print '</div></div></div></div>';
        } else if ($moduleid[0] == '30') {
            print '</tr>';
            echo '</tbody>';
            print '</table>';
            print '</div></div></div></div>';
        }

    }
}

echo $OUTPUT->footer();

// Trigger a report viewed event.
$event = \report_stardashboard\event\report_viewed::create(array('context' => $context));
$event->trigger();
