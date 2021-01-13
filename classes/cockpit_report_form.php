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
 * @package mod_ivs
 * @author Ghostthinker GmbH <info@interactive-video-suite.de>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2017 onwards Ghostthinker GmbH (https://ghostthinker.de/)
 */

namespace mod_ivs;

use core_date;
use DateTime;

global $CFG;
defined('MOODLE_INTERNAL') || die();

class cockpit_report_form {

    protected $PAGE;
    protected $course;
    protected $context;
    protected $parameters;
    private $reportservice;

    /**
     * cockpit_filter_form constructor.
     *
     * @param $PAGE
     * @param $course
     * @param $context
     * @param $rawparameters
     * @param \mod_ivs\ReportService $reportservice
     */
    public function __construct($PAGE, $course, $context, $rawparameters, ReportService $reportservice) {
        $this->PAGE = $PAGE;
        $this->course = $course;
        $this->context = $context;

        $this->reportservice = $reportservice;

        $this->parameters = $this->_parse_parameters($rawparameters);

    }

    function render() {

        global $DB;
        $for_out = "";
        // Build url and hidden fields.

        $out = "";

        // Report parameters.
        $reportaction = optional_param('report_action', null, PARAM_RAW);
        $reportid = optional_param('report_id', null, PARAM_RAW);

        // Todo: Switch case.
        if ($reportaction == 'create') {

            $out .= $this->render_form();
        } else if ($reportaction == 'update') {
            if (!empty($reportid)) {

                $report = $this->reportservice->retrieve_from_db($reportid);

                // TODO check - is this my report.

                // Build filter url.
                $newupdateurl = $this->PAGE->url;
                $newupdateurl->param("filter_users", $report->get_filter()['filter_users']);
                $newupdateurl->param("grouping", $report->get_filter()['grouping']);
                $newupdateurl->param("sortkey", $report->get_filter()['sortkey']);
                $newupdateurl->param("sortorder", $report->get_filter()['sortorder']);
                $newupdateurl->param("report_action", "update_form");
                $newupdateurl->param("report_id", $report->get_id());
                redirect($newupdateurl);
                return;

            }
        } else if ($reportaction == 'delete') {
            if (!empty($reportid)) {

                require_sesskey();

                // Access check.
                $report = $this->reportservice->retrieve_from_db($reportid);
                if (empty($report)) {
                    throw new \Exception("Report not found");
                }

                if ($this->reportservice->access_check("delete", $report)) {

                    $this->reportservice->delete_from_db($reportid);
                    $this->redirect_to_cockpit();
                } else {
                    throw new \Exception("Report access denied");
                }
            }
        } else if ($reportaction == 'update_form') {
            if (!empty($reportid)) {
                $report = $this->reportservice->retrieve_from_db($reportid);

                if (!empty($report->get_filter()['filter_users'])) {
                    $reportuser = $DB->get_record('user', array('id' => $report->get_filter()['filter_users']));
                    $username = $reportuser->firstname . " " . $reportuser->lastname;
                    $out .= "<div>" . get_string("users") . ": " . $username . "</div>";
                }

                if (!empty($report->get_filter()['filter_has_drawing'])) {
                    $out .= "<div>" . get_string("filter_label_has_drawing", 'ivs') . ": " .
                            get_string($report->get_filter()['filter_has_drawing']) . "</div>";
                }

                if (!empty($report->get_filter()['filter_rating'])) {
                    switch ($report->get_filter()['filter_rating']) {
                        case "red":
                            $rating = 'rating_option_red';
                            break;
                        case "yellow":
                            $rating = 'rating_option_yellow';
                            break;
                        case "green":
                            $rating = 'rating_option_green';
                            break;
                    }

                    $out .= "<div>" . get_string("filter_label_rating", 'ivs') . ": " . get_string($rating, 'ivs') . "</div>";
                }

                if (!empty($report->get_filter()['filter_access'])) {
                    $out .= "<div>" . get_string("filter_label_access", 'ivs') . ": " .
                            get_string('ivs:acc_label:' . $report->get_filter()['filter_access'], 'ivs') . "</div>";
                }

                if (!empty($report->get_filter()['grouping'])) {
                    $out .= "<div>" . get_string("block_grouping_title", 'ivs') . ": " .
                            get_string('ivs:acc_label:group_' . $report->get_filter()['grouping'], 'ivs') . "</div>";
                }

                $out .= $this->render_form($report);
            }

        } else {
            $out .= $this->render_listing();
        }
        return $out;

    }

    function render_form($report = null) {
        $out = "";
        $startdate = "";
        $valuerotation = "";
        $url = clone $this->PAGE->url;
        $action = "$url";

        $params = $url->params();

        // Set all existing GET parameters so paging will include sort etc.
        foreach ($params as $key => $val) {

            // Every filtering will reset the pager to 0 and the actual filter value.
            if ($key == "page" || substr($key, 0, 7) == "report_") {
                continue;
            }

            $out .= '<input type="hidden" name="' . $key . '" value="' . $val . '" />';

        }

        $startdate = date_create();

        if ($report) {
            $out .= '<input type="hidden" name="report_id" value="' . $report->getId() . '" />';
            $valuerotation = $report->getRotation();

            date_timestamp_set($startdate, date($report->getStartDate()));
            $startdate = date_format($startdate, 'd.m.Y');
        } else {
            date_timestamp_set($startdate, time());
            $startdate = date_format($startdate, 'd.m.Y');
        }

        $optionsout = "";

        $options = $this->get_rotation_options();

        foreach ($options as $option => $label) {

            $optionsout .= "<option value=" . $option;

            if ($option == $valuerotation) {
                $optionsout .= " selected=" . $valuerotation;
            }

            $optionsout .= ">" . $label . "</option>";

        }

        $out .= "<br><div class='form-item'><label>" . get_string("report_start_date", 'ivs') .
                "</label><input type=\"text\" name=\"report_start_date\" value=" . $startdate . "></div>";
        $out .= "<div class='form-item'><label>" . get_string("report_rotation", 'ivs') .
                "</label><select name=\"report_rotation\">" . $optionsout . "</select></div>";
        $out .= "<input type='submit' name='submit' value='" . get_string("save_report", 'ivs') . "'>";

        return "<form class='ivs-annotation-filter-form' method='post' action='" . $action . "'>$out</form>";
    }

    function render_listing() {

        global $USER;

        $actioncreatelink = clone $this->PAGE->url;
        $actioncreatelink->param("report_action", "create");

        $reports = $this->reportservice->getReportsByCourse($this->course->id, $USER->id);

        $out = "";

        /** @var Report $report */
        foreach ($reports as $report) {

            $filter = $report->get_filter();

            $editurl = clone $this->PAGE->url;
            $editurl->param("report_action", "update");
            $editurl->param("report_id", $report->get_id());
            $editurl->param("filter_users", $filter['filter_users']);

            $deleteurl = clone $this->PAGE->url;
            $deleteurl->param("report_action", "delete");
            $deleteurl->param("report_id", $report->get_id());
            $deleteurl->param("filter_users", $filter['filter_users']);
            $deleteurl->param("sesskey", sesskey());

            $datestring = date_format_string($report->get_startdate(), "%d %h %Y");

            $out .= get_string('block_report_title_single', 'ivs') . ", " .
                    get_string('report_rotation_' . $report->get_rotation(), 'ivs') . ", " . $datestring .
                    " <div class='form-item-report'> <a href='" . $editurl . "'>" .
                    get_string("report_edit", 'ivs') . "</a> <a href='" . $deleteurl . "'>" .
                    get_string("report_delete", 'ivs') . "</a></div><br>";
        }
        $out .= "<br><a href=$actioncreatelink><input type=\"button\" value='" . get_string("create_report", 'ivs') . "' /></a>";

        $out .= "<p>" . get_string("create_report_hint", 'ivs') . "</p>";
        return $out;

    }

    /**
     * Parse RAW user input for query values. BE CAREFUL HERE. This is raw input
     * that gets to sql!
     *
     * @param $rawparameters
     */
    private function _parse_parameters($rawparameters) {

        $parsedparameters = array();

        if (!empty($rawparameters['report_id'])) {

            $parsedparameters['report_id'] = $rawparameters['report_id'];

        }

        return $parsedparameters;
    }

    /**
     * @return array
     */
    public function get_rotation_options() {
        $options = array(
                Report::ROTATION_DAY => get_string("report_rotation_" . Report::ROTATION_DAY, 'ivs'),
                Report::ROTATION_WEEK => get_string("report_rotation_" . Report::ROTATION_WEEK, 'ivs'),
                Report::ROTATION_MONTH => get_string("report_rotation_" . Report::ROTATION_MONTH, 'ivs'),
        );
        return $options;
    }

    /**
     * Parse the raw user input so the parameters only have allowed values
     *
     * @param $key
     * @param $rawparameters
     * @param $options
     */
    private function _parse_simple_select_option_input($key, $rawparameters, $options) {
        $this->parameters[$key] = null;

        if (array_key_exists($key, $rawparameters)) {
            $rating = $rawparameters[$key];
            // Only put uid in array if it is an allowed available option.
            $ratingoptions = $options;
            if (array_key_exists($rating, $ratingoptions)) {
                $this->parameters[$key] = $rating;
            }
        }
    }

    public function get_active_filter() {
        return $this->parameters;
    }

    public function process_form($courseid, $rawuserpost, $options, $userid) {

        $startdate = $rawuserpost['report_start_date'] ? $startdate = strtotime($rawuserpost['report_start_date']) :
                $startdate = time();

        // Check rotation.
        $rotationoptions = $this->get_rotation_options();

        $rotation = array_key_exists($rawuserpost['report_rotation'], $rotationoptions) ? $rawuserpost['report_rotation'] :
                Report::ROTATION_MONTH;
        $reportid = isset($rawuserpost['report_id']) ? (int) $rawuserpost['report_id'] : null;

        // Update existing.
        if (empty($reportid)) {
            $this->reportservice->createReport($courseid, $startdate, $rotation, $options, $userid);
        } else {

            $report = $this->reportservice->retrieve_from_db($reportid);

            // Report not found.
            if (empty($report)) {
                return;
            }

            if ($this->reportservice->access_check("update", $report)) {

                $report->set_rotation($rotation);
                $report->set_startdate($startdate);

                $this->reportservice->save_to_db($report);
            }

        }
        $this->redirect_to_cockpit();

    }

    /**
     * Redirect to cokcpit with actrive filters but without report action and ids
     *
     * @throws \moodle_exception
     */
    function redirect_to_cockpit() {
        /** @var \moodle_url $newurl */
        $newurl = $this->PAGE->url;
        $newurl->remove_params(array("report_action", "report_id"));
        redirect($newurl);
    }
}
