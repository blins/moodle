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
 * External assign API
 *
 * @package    mod_assign
 * @since      Moodle 2.4
 * @copyright  2012 Paul Charsley
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");

/**
 * Assign functions
 */
class mod_assign_external extends external_api {

    /**
     * Describes the parameters for get_grades
     * @return external_external_function_parameters
     * @since  Moodle 2.4
     */
    public static function get_grades_parameters() {
        return new external_function_parameters(
            array(
                'assignmentids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'assignment id'),
                    '1 or more assignment ids',
                    VALUE_REQUIRED),
                'since' => new external_value(PARAM_INT,
                          'timestamp, only return records where timemodified >= since',
                          VALUE_DEFAULT, 0)
            )
        );
    }

    /**
     * Returns grade information from assign_grades for the requested assignment ids
     * @param array of ints $assignmentids
     * @param int $since only return records with timemodified >= since
     * @return array of grade records for each requested assignment
     * @since  Moodle 2.4
     */
    public static function get_grades($assignmentids, $since = 0) {
        global $DB;
        $params = self::validate_parameters(self::get_grades_parameters(),
                        array('assignmentids' => $assignmentids,
                              'since' => $since));

        $assignments = array();
        $warnings = array();
        $requestedassignmentids = $params['assignmentids'];

        // Check the user is allowed to get the grades for the assignments requested.
        $placeholders = array();
        list($sqlassignmentids, $placeholders) = $DB->get_in_or_equal($requestedassignmentids, SQL_PARAMS_NAMED);
        $sql = "SELECT cm.id, cm.instance FROM {course_modules} cm JOIN {modules} md ON md.id = cm.module ".
               "WHERE md.name = :modname AND cm.instance ".$sqlassignmentids;
        $placeholders['modname'] = 'assign';
        $cms = $DB->get_records_sql($sql, $placeholders);
        foreach ($cms as $cm) {
            try {
                $context = context_module::instance($cm->id);
                self::validate_context($context);
                require_capability('mod/assign:grade', $context);
            } catch (Exception $e) {
                $requestedassignmentids = array_diff($requestedassignmentids, array($cm->instance));
                $warning = array();
                $warning['item'] = 'assignment';
                $warning['itemid'] = $cm->instance;
                $warning['warningcode'] = '1';
                $warning['message'] = 'No access rights in module context';
                $warnings[] = $warning;
            }
        }

        // Create the query and populate an array of grade records from the recordset results.
        if (count ($requestedassignmentids) > 0) {
            $placeholders = array();
            list($inorequalsql, $placeholders) = $DB->get_in_or_equal($requestedassignmentids, SQL_PARAMS_NAMED);
            list($inorequalsql2, $placeholders2) = $DB->get_in_or_equal($requestedassignmentids, SQL_PARAMS_NAMED);

            $grademaxattempt = 'SELECT mxg.userid, MAX(mxg.attemptnumber) AS maxattempt
                                FROM {assign_grades} mxg
                                WHERE mxg.assignment ' . $inorequalsql2 . ' GROUP BY mxg.userid';

            $sql = "SELECT ag.id,ag.assignment,ag.userid,ag.timecreated,ag.timemodified,".
                   "ag.grader,ag.grade ".
                   "FROM {assign_grades} ag ".
                   "JOIN ( " . $grademaxattempt . " ) gmx ON ag.userid = gmx.userid".
                   " WHERE ag.assignment ".$inorequalsql.
                   " AND ag.timemodified  >= :since".
                   " AND ag.attemptnumber = gmx.maxattempt" .
                   " ORDER BY ag.assignment, ag.id";
            $placeholders['since'] = $params['since'];
            // Combine the parameters.
            $placeholders += $placeholders2;
            $rs = $DB->get_recordset_sql($sql, $placeholders);
            $currentassignmentid = null;
            $assignment = null;
            foreach ($rs as $rd) {
                $grade = array();
                $grade['id'] = $rd->id;
                $grade['userid'] = $rd->userid;
                $grade['timecreated'] = $rd->timecreated;
                $grade['timemodified'] = $rd->timemodified;
                $grade['grader'] = $rd->grader;
                $grade['grade'] = (string)$rd->grade;

                if (is_null($currentassignmentid) || ($rd->assignment != $currentassignmentid )) {
                    if (!is_null($assignment)) {
                        $assignments[] = $assignment;
                    }
                    $assignment = array();
                    $assignment['assignmentid'] = $rd->assignment;
                    $assignment['grades'] = array();
                    $requestedassignmentids = array_diff($requestedassignmentids, array($rd->assignment));
                }
                $assignment['grades'][] = $grade;

                $currentassignmentid = $rd->assignment;
            }
            if (!is_null($assignment)) {
                $assignments[] = $assignment;
            }
            $rs->close();
        }
        foreach ($requestedassignmentids as $assignmentid) {
            $warning = array();
            $warning['item'] = 'assignment';
            $warning['itemid'] = $assignmentid;
            $warning['warningcode'] = '3';
            $warning['message'] = 'No grades found';
            $warnings[] = $warning;
        }

        $result = array();
        $result['assignments'] = $assignments;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Creates an assign_grades external_single_structure
     * @return external_single_structure
     * @since  Moodle 2.4
     */
    private static function assign_grades() {
        return new external_single_structure(
            array (
                'assignmentid'    => new external_value(PARAM_INT, 'assignment id'),
                'grades'   => new external_multiple_structure(new external_single_structure(
                        array(
                            'id'            => new external_value(PARAM_INT, 'grade id'),
                            'userid'        => new external_value(PARAM_INT, 'student id'),
                            'timecreated'   => new external_value(PARAM_INT, 'grade creation time'),
                            'timemodified'  => new external_value(PARAM_INT, 'grade last modified time'),
                            'grader'        => new external_value(PARAM_INT, 'grader'),
                            'grade'         => new external_value(PARAM_TEXT, 'grade')
                        )
                    )
                )
            )
        );
    }

    /**
     * Describes the get_grades return value
     * @return external_single_structure
     * @since  Moodle 2.4
     */
    public static function get_grades_returns() {
        return new external_single_structure(
            array(
                'assignments' => new external_multiple_structure(self::assign_grades(), 'list of assignment grade information'),
                'warnings'      => new external_warnings('item is always \'assignment\'',
                    'when errorcode is 3 then itemid is an assignment id. When errorcode is 1, itemid is a course module id',
                    'errorcode can be 3 (no grades found) or 1 (no permission to get grades)')
            )
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since  Moodle 2.4
     */
    public static function get_assignments_parameters() {
        return new external_function_parameters(
            array(
                'courseids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'course id'),
                    '0 or more course ids',
                    VALUE_DEFAULT, array()
                ),
                'capabilities'  => new external_multiple_structure(
                    new external_value(PARAM_CAPABILITY, 'capability'),
                    'list of capabilities used to filter courses',
                    VALUE_DEFAULT, array()
                )
            )
        );
    }

    /**
     * Returns an array of courses the user is enrolled in, and for each course all of the assignments that the user can
     * view within that course.
     *
     * @param array $courseids An optional array of course ids. If provided only assignments within the given course
     * will be returned. If the user is not enrolled in a given course a warning will be generated and returned.
     * @param array $capabilities An array of additional capability checks you wish to be made on the course context.
     * @return An array of courses and warnings.
     * @since  Moodle 2.4
     */
    public static function get_assignments($courseids = array(), $capabilities = array()) {
        global $USER, $DB;

        $params = self::validate_parameters(
            self::get_assignments_parameters(),
            array('courseids' => $courseids, 'capabilities' => $capabilities)
        );

        $warnings = array();
        $fields = 'sortorder,shortname,fullname,timemodified';
        $courses = enrol_get_users_courses($USER->id, true, $fields);
        // Used to test for ids that have been requested but can't be returned.
        if (count($params['courseids']) > 0) {
            foreach ($params['courseids'] as $courseid) {
                if (!in_array($courseid, array_keys($courses))) {
                    unset($courses[$courseid]);
                    $warnings[] = array(
                        'item' => 'course',
                        'itemid' => $courseid,
                        'warningcode' => '2',
                        'message' => 'User is not enrolled or does not have requested capability'
                    );
                }
            }
        }
        foreach ($courses as $id => $course) {
            if (count($params['courseids']) > 0 && !in_array($id, $params['courseids'])) {
                unset($courses[$id]);
            }
            $context = context_course::instance($id);
            try {
                self::validate_context($context);
            } catch (Exception $e) {
                unset($courses[$id]);
                $warnings[] = array(
                    'item' => 'course',
                    'itemid' => $id,
                    'warningcode' => '1',
                    'message' => 'No access rights in course context '.$e->getMessage().$e->getTraceAsString()
                );
                continue;
            }
            if (count($params['capabilities']) > 0 && !has_all_capabilities($params['capabilities'], $context)) {
                unset($courses[$id]);
            }
        }
        $extrafields='m.id as assignmentid, m.course, m.nosubmissions, m.submissiondrafts, m.sendnotifications, '.
                     'm.sendlatenotifications, m.duedate, m.allowsubmissionsfromdate, m.grade, m.timemodified, '.
                     'm.completionsubmit, m.cutoffdate, m.teamsubmission, m.requireallteammemberssubmit, '.
                     'm.teamsubmissiongroupingid, m.blindmarking, m.revealidentities, m.attemptreopenmethod, '.
                     'm.maxattempts, m.markingworkflow, m.markingallocation, m.requiresubmissionstatement';
        $coursearray = array();
        foreach ($courses as $id => $course) {
            $assignmentarray = array();
            // Get a list of assignments for the course.
            if ($modules = get_coursemodules_in_course('assign', $courses[$id]->id, $extrafields)) {
                foreach ($modules as $module) {
                    $context = context_module::instance($module->id);
                    try {
                        self::validate_context($context);
                        require_capability('mod/assign:view', $context);
                    } catch (Exception $e) {
                        $warnings[] = array(
                            'item' => 'module',
                            'itemid' => $module->id,
                            'warningcode' => '1',
                            'message' => 'No access rights in module context'
                        );
                        continue;
                    }
                    $configrecords = $DB->get_recordset('assign_plugin_config', array('assignment' => $module->assignmentid));
                    $configarray = array();
                    foreach ($configrecords as $configrecord) {
                        $configarray[] = array(
                            'id' => $configrecord->id,
                            'assignment' => $configrecord->assignment,
                            'plugin' => $configrecord->plugin,
                            'subtype' => $configrecord->subtype,
                            'name' => $configrecord->name,
                            'value' => $configrecord->value
                        );
                    }
                    $configrecords->close();

                    $assignmentarray[]= array(
                        'id' => $module->assignmentid,
                        'cmid' => $module->id,
                        'course' => $module->course,
                        'name' => $module->name,
                        'nosubmissions' => $module->nosubmissions,
                        'submissiondrafts' => $module->submissiondrafts,
                        'sendnotifications' => $module->sendnotifications,
                        'sendlatenotifications' => $module->sendlatenotifications,
                        'duedate' => $module->duedate,
                        'allowsubmissionsfromdate' => $module->allowsubmissionsfromdate,
                        'grade' => $module->grade,
                        'timemodified' => $module->timemodified,
                        'completionsubmit' => $module->completionsubmit,
                        'cutoffdate' => $module->cutoffdate,
                        'teamsubmission' => $module->teamsubmission,
                        'requireallteammemberssubmit' => $module->requireallteammemberssubmit,
                        'teamsubmissiongroupingid' => $module->teamsubmissiongroupingid,
                        'blindmarking' => $module->blindmarking,
                        'revealidentities' => $module->revealidentities,
                        'attemptreopenmethod' => $module->attemptreopenmethod,
                        'maxattempts' => $module->maxattempts,
                        'markingworkflow' => $module->markingworkflow,
                        'markingallocation' => $module->markingallocation,
                        'requiresubmissionstatement' => $module->requiresubmissionstatement,
                        'configs' => $configarray
                    );
                }
            }
            $coursearray[]= array(
                'id' => $courses[$id]->id,
                'fullname' => $courses[$id]->fullname,
                'shortname' => $courses[$id]->shortname,
                'timemodified' => $courses[$id]->timemodified,
                'assignments' => $assignmentarray
            );
        }

        $result = array(
            'courses' => $coursearray,
            'warnings' => $warnings
        );
        return $result;
    }

    /**
     * Creates an assignment external_single_structure
     *
     * @return external_single_structure
     * @since Moodle 2.4
     */
    private static function get_assignments_assignment_structure() {
        return new external_single_structure(
            array(
                'id' => new external_value(PARAM_INT, 'assignment id'),
                'cmid' => new external_value(PARAM_INT, 'course module id'),
                'course' => new external_value(PARAM_INT, 'course id'),
                'name' => new external_value(PARAM_TEXT, 'assignment name'),
                'nosubmissions' => new external_value(PARAM_INT, 'no submissions'),
                'submissiondrafts' => new external_value(PARAM_INT, 'submissions drafts'),
                'sendnotifications' => new external_value(PARAM_INT, 'send notifications'),
                'sendlatenotifications' => new external_value(PARAM_INT, 'send notifications'),
                'duedate' => new external_value(PARAM_INT, 'assignment due date'),
                'allowsubmissionsfromdate' => new external_value(PARAM_INT, 'allow submissions from date'),
                'grade' => new external_value(PARAM_INT, 'grade type'),
                'timemodified' => new external_value(PARAM_INT, 'last time assignment was modified'),
                'completionsubmit' => new external_value(PARAM_INT, 'if enabled, set activity as complete following submission'),
                'cutoffdate' => new external_value(PARAM_INT, 'date after which submission is not accepted without an extension'),
                'teamsubmission' => new external_value(PARAM_INT, 'if enabled, students submit as a team'),
                'requireallteammemberssubmit' => new external_value(PARAM_INT, 'if enabled, all team members must submit'),
                'teamsubmissiongroupingid' => new external_value(PARAM_INT, 'the grouping id for the team submission groups'),
                'blindmarking' => new external_value(PARAM_INT, 'if enabled, hide identities until reveal identities actioned'),
                'revealidentities' => new external_value(PARAM_INT, 'show identities for a blind marking assignment'),
                'attemptreopenmethod' => new external_value(PARAM_TEXT, 'method used to control opening new attempts'),
                'maxattempts' => new external_value(PARAM_INT, 'maximum number of attempts allowed'),
                'markingworkflow' => new external_value(PARAM_INT, 'enable marking workflow'),
                'markingallocation' => new external_value(PARAM_INT, 'enable marking allocation'),
                'requiresubmissionstatement' => new external_value(PARAM_INT, 'student must accept submission statement'),
                'configs' => new external_multiple_structure(self::get_assignments_config_structure(), 'configuration settings')
            ), 'assignment information object');
    }

    /**
     * Creates an assign_plugin_config external_single_structure
     *
     * @return external_single_structure
     * @since Moodle 2.4
     */
    private static function get_assignments_config_structure() {
        return new external_single_structure(
            array(
                'id' => new external_value(PARAM_INT, 'assign_plugin_config id'),
                'assignment' => new external_value(PARAM_INT, 'assignment id'),
                'plugin' => new external_value(PARAM_TEXT, 'plugin'),
                'subtype' => new external_value(PARAM_TEXT, 'subtype'),
                'name' => new external_value(PARAM_TEXT, 'name'),
                'value' => new external_value(PARAM_TEXT, 'value')
            ), 'assignment configuration object'
        );
    }

    /**
     * Creates a course external_single_structure
     *
     * @return external_single_structure
     * @since Moodle 2.4
     */
    private static function get_assignments_course_structure() {
        return new external_single_structure(
            array(
                'id' => new external_value(PARAM_INT, 'course id'),
                'fullname' => new external_value(PARAM_TEXT, 'course full name'),
                'shortname' => new external_value(PARAM_TEXT, 'course short name'),
                'timemodified' => new external_value(PARAM_INT, 'last time modified'),
                'assignments' => new external_multiple_structure(self::get_assignments_assignment_structure(), 'assignment info')
              ), 'course information object'
        );
    }

    /**
     * Describes the return value for get_assignments
     *
     * @return external_single_structure
     * @since Moodle 2.4
     */
    public static function get_assignments_returns() {
        return new external_single_structure(
            array(
                'courses' => new external_multiple_structure(self::get_assignments_course_structure(), 'list of courses'),
                'warnings'  => new external_warnings('item can be \'course\' (errorcode 1 or 2) or \'module\' (errorcode 1)',
                    'When item is a course then itemid is a course id. When the item is a module then itemid is a module id',
                    'errorcode can be 1 (no access rights) or 2 (not enrolled or no permissions)')
            )
        );
    }

    /**
     * Describes the parameters for get_submissions
     *
     * @return external_external_function_parameters
     * @since Moodle 2.5
     */
    public static function get_submissions_parameters() {
        return new external_function_parameters(
            array(
                'assignmentids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'assignment id'),
                    '1 or more assignment ids',
                    VALUE_REQUIRED),
                'status' => new external_value(PARAM_ALPHA, 'status', VALUE_DEFAULT, ''),
                'since' => new external_value(PARAM_INT, 'submitted since', VALUE_DEFAULT, 0),
                'before' => new external_value(PARAM_INT, 'submitted before', VALUE_DEFAULT, 0)
            )
        );
    }

    /**
     * Returns submissions for the requested assignment ids
     *
     * @param array of ints $assignmentids
     * @param string $status only return submissions with this status
     * @param int $since only return submissions with timemodified >= since
     * @param int $before only return submissions with timemodified <= before
     * @return array of submissions for each requested assignment
     * @since Moodle 2.5
     */
    public static function get_submissions($assignmentids, $status = '', $since = 0, $before = 0) {
        global $DB, $CFG;
        require_once("$CFG->dirroot/mod/assign/locallib.php");
        $params = self::validate_parameters(self::get_submissions_parameters(),
                        array('assignmentids' => $assignmentids,
                              'status' => $status,
                              'since' => $since,
                              'before' => $before));

        $warnings = array();
        $assignments = array();

        // Check the user is allowed to get the submissions for the assignments requested.
        $placeholders = array();
        list($inorequalsql, $placeholders) = $DB->get_in_or_equal($params['assignmentids'], SQL_PARAMS_NAMED);
        $sql = "SELECT cm.id, cm.instance FROM {course_modules} cm JOIN {modules} md ON md.id = cm.module ".
               "WHERE md.name = :modname AND cm.instance ".$inorequalsql;
        $placeholders['modname'] = 'assign';
        $cms = $DB->get_records_sql($sql, $placeholders);
        $assigns = array();
        foreach ($cms as $cm) {
            try {
                $context = context_module::instance($cm->id);
                self::validate_context($context);
                require_capability('mod/assign:grade', $context);
                $assign = new assign($context, null, null);
                $assigns[] = $assign;
            } catch (Exception $e) {
                $warnings[] = array(
                    'item' => 'assignment',
                    'itemid' => $cm->instance,
                    'warningcode' => '1',
                    'message' => 'No access rights in module context'
                );
            }
        }

        foreach ($assigns as $assign) {
            $submissions = array();
            $submissionplugins = $assign->get_submission_plugins();
            $placeholders = array('assignid1' => $assign->get_instance()->id,
                                  'assignid2' => $assign->get_instance()->id);

            $submissionmaxattempt = 'SELECT mxs.userid, MAX(mxs.attemptnumber) AS maxattempt
                                     FROM {assign_submission} mxs
                                     WHERE mxs.assignment = :assignid1 GROUP BY mxs.userid';

            $sql = "SELECT mas.id, mas.assignment,mas.userid,".
                   "mas.timecreated,mas.timemodified,mas.status,mas.groupid ".
                   "FROM {assign_submission} mas ".
                   "JOIN ( " . $submissionmaxattempt . " ) smx ON mas.userid = smx.userid ".
                   "WHERE mas.assignment = :assignid2 AND mas.attemptnumber = smx.maxattempt";

            if (!empty($params['status'])) {
                $placeholders['status'] = $params['status'];
                $sql = $sql." AND mas.status = :status";
            }
            if (!empty($params['before'])) {
                $placeholders['since'] = $params['since'];
                $placeholders['before'] = $params['before'];
                $sql = $sql." AND mas.timemodified BETWEEN :since AND :before";
            } else {
                $placeholders['since'] = $params['since'];
                $sql = $sql." AND mas.timemodified >= :since";
            }

            $submissionrecords = $DB->get_records_sql($sql, $placeholders);

            if (!empty($submissionrecords)) {
                $fs = get_file_storage();
                foreach ($submissionrecords as $submissionrecord) {
                    $submission = array(
                        'id' => $submissionrecord->id,
                        'userid' => $submissionrecord->userid,
                        'timecreated' => $submissionrecord->timecreated,
                        'timemodified' => $submissionrecord->timemodified,
                        'status' => $submissionrecord->status,
                        'groupid' => $submissionrecord->groupid
                    );
                    foreach ($submissionplugins as $submissionplugin) {
                        $plugin = array(
                            'name' => $submissionplugin->get_name(),
                            'type' => $submissionplugin->get_type()
                        );
                        // Subtype is 'assignsubmission', type is currently 'file' or 'onlinetext'.
                        $component = $submissionplugin->get_subtype().'_'.$submissionplugin->get_type();

                        $fileareas = $submissionplugin->get_file_areas();
                        foreach ($fileareas as $filearea => $name) {
                            $fileareainfo = array('area' => $filearea);
                            $files = $fs->get_area_files(
                                $assign->get_context()->id,
                                $component,
                                $filearea,
                                $submissionrecord->id,
                                "timemodified",
                                false
                            );
                            foreach ($files as $file) {
                                $filepath = array('filepath' => $file->get_filepath().$file->get_filename());
                                $fileareainfo['files'][] = $filepath;
                            }
                            $plugin['fileareas'][] = $fileareainfo;
                        }

                        $editorfields = $submissionplugin->get_editor_fields();
                        foreach ($editorfields as $name => $description) {
                            $editorfieldinfo = array(
                                'name' => $name,
                                'description' => $description,
                                'text' => $submissionplugin->get_editor_text($name, $submissionrecord->id),
                                'format' => $submissionplugin->get_editor_format($name, $submissionrecord->id)
                            );
                            $plugin['editorfields'][] = $editorfieldinfo;
                        }

                        $submission['plugins'][] = $plugin;
                    }
                    $submissions[] = $submission;
                }
            } else {
                $warnings[] = array(
                    'item' => 'module',
                    'itemid' => $assign->get_instance()->id,
                    'warningcode' => '3',
                    'message' => 'No submissions found'
                );
            }

            $assignments[] = array(
                'assignmentid' => $assign->get_instance()->id,
                'submissions' => $submissions
            );

        }

        $result = array(
            'assignments' => $assignments,
            'warnings' => $warnings
        );
        return $result;
    }

    /**
     * Creates an assign_submissions external_single_structure
     *
     * @return external_single_structure
     * @since Moodle 2.5
     */
    private static function get_submissions_structure() {
        return new external_single_structure(
            array (
                'assignmentid' => new external_value(PARAM_INT, 'assignment id'),
                'submissions' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id' => new external_value(PARAM_INT, 'submission id'),
                            'userid' => new external_value(PARAM_INT, 'student id'),
                            'timecreated' => new external_value(PARAM_INT, 'submission creation time'),
                            'timemodified' => new external_value(PARAM_INT, 'submission last modified time'),
                            'status' => new external_value(PARAM_TEXT, 'submission status'),
                            'groupid' => new external_value(PARAM_INT, 'group id'),
                            'plugins' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'type' => new external_value(PARAM_TEXT, 'submission plugin type'),
                                        'name' => new external_value(PARAM_TEXT, 'submission plugin name'),
                                        'fileareas' => new external_multiple_structure(
                                            new external_single_structure(
                                                array (
                                                    'area' => new external_value (PARAM_TEXT, 'file area'),
                                                    'files' => new external_multiple_structure(
                                                        new external_single_structure(
                                                            array (
                                                                'filepath' => new external_value (PARAM_TEXT, 'file path')
                                                            )
                                                        ), 'files', VALUE_OPTIONAL
                                                    )
                                                )
                                            ), 'fileareas', VALUE_OPTIONAL
                                        ),
                                        'editorfields' => new external_multiple_structure(
                                            new external_single_structure(
                                                array(
                                                    'name' => new external_value(PARAM_TEXT, 'field name'),
                                                    'description' => new external_value(PARAM_TEXT, 'field description'),
                                                    'text' => new external_value (PARAM_RAW, 'field value'),
                                                    'format' => new external_format_value ('text')
                                                )
                                            )
                                            , 'editorfields', VALUE_OPTIONAL
                                        )
                                    )
                                )
                                , 'plugins', VALUE_OPTIONAL
                            )
                        )
                    )
                )
            )
        );
    }

    /**
     * Describes the get_submissions return value
     *
     * @return external_single_structure
     * @since Moodle 2.5
     */
    public static function get_submissions_returns() {
        return new external_single_structure(
            array(
                'assignments' => new external_multiple_structure(self::get_submissions_structure(), 'assignment submissions'),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Describes the parameters for get_user_flags
     * @return external_function_parameters
     * @since  Moodle 2.6
     */
    public static function get_user_flags_parameters() {
        return new external_function_parameters(
            array(
                'assignmentids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'assignment id'),
                    '1 or more assignment ids',
                    VALUE_REQUIRED)
            )
        );
    }

    /**
     * Returns user flag information from assign_user_flags for the requested assignment ids
     * @param array of ints $assignmentids
     * @return array of user flag records for each requested assignment
     * @since  Moodle 2.6
     */
    public static function get_user_flags($assignmentids) {
        global $DB;
        $params = self::validate_parameters(self::get_user_flags_parameters(),
                        array('assignmentids' => $assignmentids));

        $assignments = array();
        $warnings = array();
        $requestedassignmentids = $params['assignmentids'];

        // Check the user is allowed to get the user flags for the assignments requested.
        $placeholders = array();
        list($sqlassignmentids, $placeholders) = $DB->get_in_or_equal($requestedassignmentids, SQL_PARAMS_NAMED);
        $sql = "SELECT cm.id, cm.instance FROM {course_modules} cm JOIN {modules} md ON md.id = cm.module ".
               "WHERE md.name = :modname AND cm.instance ".$sqlassignmentids;
        $placeholders['modname'] = 'assign';
        $cms = $DB->get_records_sql($sql, $placeholders);
        foreach ($cms as $cm) {
            try {
                $context = context_module::instance($cm->id);
                self::validate_context($context);
                require_capability('mod/assign:grade', $context);
            } catch (Exception $e) {
                $requestedassignmentids = array_diff($requestedassignmentids, array($cm->instance));
                $warning = array();
                $warning['item'] = 'assignment';
                $warning['itemid'] = $cm->instance;
                $warning['warningcode'] = '1';
                $warning['message'] = 'No access rights in module context';
                $warnings[] = $warning;
            }
        }

        // Create the query and populate an array of assign_user_flags records from the recordset results.
        if (count ($requestedassignmentids) > 0) {
            $placeholders = array();
            list($inorequalsql, $placeholders) = $DB->get_in_or_equal($requestedassignmentids, SQL_PARAMS_NAMED);

            $sql = "SELECT auf.id,auf.assignment,auf.userid,auf.locked,auf.mailed,".
                   "auf.extensionduedate,auf.workflowstate,auf.allocatedmarker ".
                   "FROM {assign_user_flags} auf ".
                   "WHERE auf.assignment ".$inorequalsql.
                   " ORDER BY auf.assignment, auf.id";

            $rs = $DB->get_recordset_sql($sql, $placeholders);
            $currentassignmentid = null;
            $assignment = null;
            foreach ($rs as $rd) {
                $userflag = array();
                $userflag['id'] = $rd->id;
                $userflag['userid'] = $rd->userid;
                $userflag['locked'] = $rd->locked;
                $userflag['mailed'] = $rd->mailed;
                $userflag['extensionduedate'] = $rd->extensionduedate;
                $userflag['workflowstate'] = $rd->workflowstate;
                $userflag['allocatedmarker'] = $rd->allocatedmarker;

                if (is_null($currentassignmentid) || ($rd->assignment != $currentassignmentid )) {
                    if (!is_null($assignment)) {
                        $assignments[] = $assignment;
                    }
                    $assignment = array();
                    $assignment['assignmentid'] = $rd->assignment;
                    $assignment['userflags'] = array();
                    $requestedassignmentids = array_diff($requestedassignmentids, array($rd->assignment));
                }
                $assignment['userflags'][] = $userflag;

                $currentassignmentid = $rd->assignment;
            }
            if (!is_null($assignment)) {
                $assignments[] = $assignment;
            }
            $rs->close();

        }

        foreach ($requestedassignmentids as $assignmentid) {
            $warning = array();
            $warning['item'] = 'assignment';
            $warning['itemid'] = $assignmentid;
            $warning['warningcode'] = '3';
            $warning['message'] = 'No user flags found';
            $warnings[] = $warning;
        }

        $result = array();
        $result['assignments'] = $assignments;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Creates an assign_user_flags external_single_structure
     * @return external_single_structure
     * @since  Moodle 2.6
     */
    private static function assign_user_flags() {
        return new external_single_structure(
            array (
                'assignmentid'    => new external_value(PARAM_INT, 'assignment id'),
                'userflags'   => new external_multiple_structure(new external_single_structure(
                        array(
                            'id'               => new external_value(PARAM_INT, 'user flag id'),
                            'userid'           => new external_value(PARAM_INT, 'student id'),
                            'locked'           => new external_value(PARAM_INT, 'locked'),
                            'mailed'           => new external_value(PARAM_INT, 'mailed'),
                            'extensionduedate' => new external_value(PARAM_INT, 'extension due date'),
                            'workflowstate'    => new external_value(PARAM_TEXT, 'marking workflow state', VALUE_OPTIONAL),
                            'allocatedmarker'  => new external_value(PARAM_INT, 'allocated marker')
                        )
                    )
                )
            )
        );
    }

    /**
     * Describes the get_user_flags return value
     * @return external_single_structure
     * @since  Moodle 2.6
     */
    public static function get_user_flags_returns() {
        return new external_single_structure(
            array(
                'assignments' => new external_multiple_structure(self::assign_user_flags(), 'list of assign user flag information'),
                'warnings'      => new external_warnings('item is always \'assignment\'',
                    'when errorcode is 3 then itemid is an assignment id. When errorcode is 1, itemid is a course module id',
                    'errorcode can be 3 (no user flags found) or 1 (no permission to get user flags)')
            )
        );
    }

    /**
     * Describes the parameters for get_user_mappings
     * @return external_function_parameters
     * @since  Moodle 2.6
     */
    public static function get_user_mappings_parameters() {
        return new external_function_parameters(
            array(
                'assignmentids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'assignment id'),
                    '1 or more assignment ids',
                    VALUE_REQUIRED)
            )
        );
    }

    /**
     * Returns user mapping information from assign_user_mapping for the requested assignment ids
     * @param array of ints $assignmentids
     * @return array of user mapping records for each requested assignment
     * @since  Moodle 2.6
     */
    public static function get_user_mappings($assignmentids) {
        global $DB;
        $params = self::validate_parameters(self::get_user_mappings_parameters(),
                        array('assignmentids' => $assignmentids));

        $assignments = array();
        $warnings = array();
        $requestedassignmentids = $params['assignmentids'];

        // Check the user is allowed to get the mappings for the assignments requested.
        $placeholders = array();
        list($sqlassignmentids, $placeholders) = $DB->get_in_or_equal($requestedassignmentids, SQL_PARAMS_NAMED);
        $sql = "SELECT cm.id, cm.instance FROM {course_modules} cm JOIN {modules} md ON md.id = cm.module ".
               "WHERE md.name = :modname AND cm.instance ".$sqlassignmentids;
        $placeholders['modname'] = 'assign';
        $cms = $DB->get_records_sql($sql, $placeholders);
        foreach ($cms as $cm) {
            try {
                $context = context_module::instance($cm->id);
                self::validate_context($context);
                require_capability('mod/assign:revealidentities', $context);
            } catch (Exception $e) {
                $requestedassignmentids = array_diff($requestedassignmentids, array($cm->instance));
                $warning = array();
                $warning['item'] = 'assignment';
                $warning['itemid'] = $cm->instance;
                $warning['warningcode'] = '1';
                $warning['message'] = 'No access rights in module context';
                $warnings[] = $warning;
            }
        }

        // Create the query and populate an array of assign_user_mapping records from the recordset results.
        if (count ($requestedassignmentids) > 0) {
            $placeholders = array();
            list($inorequalsql, $placeholders) = $DB->get_in_or_equal($requestedassignmentids, SQL_PARAMS_NAMED);

            $sql = "SELECT aum.id,aum.assignment,aum.userid ".
                   "FROM {assign_user_mapping} aum ".
                   "WHERE aum.assignment ".$inorequalsql.
                   " ORDER BY aum.assignment, aum.id";

            $rs = $DB->get_recordset_sql($sql, $placeholders);
            $currentassignmentid = null;
            $assignment = null;
            foreach ($rs as $rd) {
                $mapping = array();
                $mapping['id'] = $rd->id;
                $mapping['userid'] = $rd->userid;

                if (is_null($currentassignmentid) || ($rd->assignment != $currentassignmentid )) {
                    if (!is_null($assignment)) {
                        $assignments[] = $assignment;
                    }
                    $assignment = array();
                    $assignment['assignmentid'] = $rd->assignment;
                    $assignment['mappings'] = array();
                    $requestedassignmentids = array_diff($requestedassignmentids, array($rd->assignment));
                }
                $assignment['mappings'][] = $mapping;

                $currentassignmentid = $rd->assignment;
            }
            if (!is_null($assignment)) {
                $assignments[] = $assignment;
            }
            $rs->close();

        }

        foreach ($requestedassignmentids as $assignmentid) {
            $warning = array();
            $warning['item'] = 'assignment';
            $warning['itemid'] = $assignmentid;
            $warning['warningcode'] = '3';
            $warning['message'] = 'No mappings found';
            $warnings[] = $warning;
        }

        $result = array();
        $result['assignments'] = $assignments;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Creates an assign_user_mappings external_single_structure
     * @return external_single_structure
     * @since  Moodle 2.6
     */
    private static function assign_user_mappings() {
        return new external_single_structure(
            array (
                'assignmentid'    => new external_value(PARAM_INT, 'assignment id'),
                'mappings'   => new external_multiple_structure(new external_single_structure(
                        array(
                            'id'     => new external_value(PARAM_INT, 'user mapping id'),
                            'userid' => new external_value(PARAM_INT, 'student id')
                        )
                    )
                )
            )
        );
    }

    /**
     * Describes the get_user_mappings return value
     * @return external_single_structure
     * @since  Moodle 2.6
     */
    public static function get_user_mappings_returns() {
        return new external_single_structure(
            array(
                'assignments' => new external_multiple_structure(self::assign_user_mappings(), 'list of assign user mapping data'),
                'warnings'      => new external_warnings('item is always \'assignment\'',
                    'when errorcode is 3 then itemid is an assignment id. When errorcode is 1, itemid is a course module id',
                    'errorcode can be 3 (no user mappings found) or 1 (no permission to get user mappings)')
            )
        );
    }

}
