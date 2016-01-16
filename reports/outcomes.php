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
 *
 * @package mod
 * @subpackage emarking
 * @copyright 2014 Jorge Villalon <villalon@gmail.com>
 * @copyright 2014 Nicolas Perez <niperez@alumnos.uai.cl>
 * @copyright 2014 Carlos Villarroel <cavillarroel@alumnos.uai.cl>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
require_once($CFG->dirroot . '/mod/emarking/locallib.php');
require_once('forms/gradereport_form.php');

global $DB, $USER;

// Get course module id
$cmid = required_param('id', PARAM_INT);

// Validate course module
if(!$cm = get_coursemodule_from_id('emarking', $cmid)) {
        print_error('Módulo inválido');
}

// Validate module
if(!$emarking = $DB->get_record('emarking', array('id'=>$cm->instance))) {
        print_error('Prueba inválida');
}

// Validate course
if(!$course = $DB->get_record('course', array('id'=>$emarking->course))) {
        print_error('Curso inválido');
}

// URLs for current page
$url = new moodle_url('/mod/emarking/reports/grade.php', array('id'=>$cm->id));

// Course context is used in reports
$context = context_module::instance($cm->id);

// Validate the user has grading capabilities
require_capability ( 'mod/emarking:grade', $context );

// First check that the user is logged in
require_login($course->id);
if (isguestuser()) {
        die();
}

// Page settings (URL, breadcrumbs and title)
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->navbar->add(get_string('gradereport','grades'));


echo $OUTPUT->header();
echo $OUTPUT->heading($emarking->name);

// Print eMarking tabs
echo $OUTPUT->tabtree(emarking_tabs($context, $cm, $emarking), "report" );

$totalsubmissions = $DB->count_records_sql("
                SELECT COUNT(DISTINCT s.id) AS total 
                FROM {emarking_submission} AS s
                INNER JOIN {emarking_draft} AS d 
                    ON (s.emarking = :emarking AND d.status >= " . EMARKING_STATUS_PUBLISHED . " AND d.submissionid = s.id AND d.grade > 0 AND d.qualitycontrol=0)
                ", array('emarking' => $emarking->id));

if(!$totalsubmissions || $totalsubmissions == 0) {
        echo $OUTPUT->notification(get_string('nosubmissionspublished','mod_emarking'),'notifyproblem');
        echo $OUTPUT->footer();
        die();
}

$emarkingids = ''. $emarking->id;

$extracategory = optional_param('categories', 0, PARAM_INT);

$parallels = emarking_get_parallel_courses($course);

$emarkingsform = new emarking_gradereport_form(null, array('course'=>$course, 'cm'=>$cm, 'parallels'=>$parallels, 'id'=>$emarkingids));

$emarkingsform->display();

$totalemarkings = 1;
if($parallels && count($parallels) > 0) {
        foreach($parallels as $pcourse) {
                $assid = '';
                if($emarkingsform->get_data() && property_exists($emarkingsform->get_data(),"emarkingid_$pcourse->id")) {
                        eval("\$assid = \$emarkingsform->get_data()->emarkingid_$pcourse->id;");
                        if($assid > 0) {
                                $emarkingids .= ',' . $assid;
                                $totalemarkings++;
                        }
                }
        }
}

$sqlcats = "SELECT COUNT(DISTINCT(c.category)) AS categories
FROM {emarking} AS a
INNER JOIN {course} AS c ON (a.course = c.id)
WHERE a.id IN ($emarkingids)";

$totalcategories = $DB->count_records_sql($sqlcats);

// Get the grading manager, then method and finally controller
$gradingmanager = get_grading_manager($context, 'mod_emarking', 'attempt');
$gradingmethod = $gradingmanager->get_active_method();
$rubriccontroller = $gradingmanager->get_controller($gradingmethod);
$definition = $rubriccontroller->get_definition();

$sql = "SELECT  
*,
CASE
    WHEN categoryid IS NULL THEN 'TOTAL'
    WHEN emarkingid IS NULL THEN CONCAT('SUBTOTAL ', categoryname)
    ELSE coursename
END AS seriesname
FROM (
    SELECT
        categoryid AS categoryid,
        categoryname,
        emarkingid AS emarkingid,
        modulename,
        coursename,
        COUNT(*) AS students,
        SUM(pass) AS pass,
        ROUND((SUM(pass) / COUNT(*)) * 100,2) AS pass_ratio,
        SUBSTRING_INDEX(SUBSTRING_INDEX(GROUP_CONCAT(grade ORDER BY grade SEPARATOR ','), ',', 25/100 * COUNT(*) + 1), ',', -1) AS percentile_25,
        SUBSTRING_INDEX(SUBSTRING_INDEX(GROUP_CONCAT(grade ORDER BY grade SEPARATOR ','), ',', 50/100 * COUNT(*) + 1), ',', -1) AS percentile_50,
        SUBSTRING_INDEX(SUBSTRING_INDEX(GROUP_CONCAT(grade ORDER BY grade SEPARATOR ','), ',', 75/100 * COUNT(*) + 1), ',', -1) AS percentile_75,
        MIN(grade) AS minimum,
        MAX(grade) AS maximum,
        ROUND(avg(grade),2) AS average,
        ROUND(stddev(grade),2) AS stdev,
        SUM(histogram_01) AS histogram_1,
        SUM(histogram_02) AS histogram_2,
        SUM(histogram_03) AS histogram_3,
        SUM(histogram_04) AS histogram_4,
        SUM(histogram_05) AS histogram_5,
        SUM(histogram_06) AS histogram_6,
        SUM(histogram_07) AS histogram_7,
        SUM(histogram_08) AS histogram_8,
        SUM(histogram_09) AS histogram_9,
        SUM(histogram_10) AS histogram_10,
        SUM(histogram_11) AS histogram_11,
        SUM(histogram_12) AS histogram_12,
        ROUND(SUM(rank_1)/COUNT(*),3) AS rank_1,
        ROUND(SUM(rank_2)/COUNT(*),3) AS rank_2,
        ROUND(SUM(rank_3)/COUNT(*),3) AS rank_3,
        MIN(mingrade) AS mingradeemarking,
        MIN(maxgrade) AS maxgradeemarking
        FROM (
            SELECT
            ROUND(d.grade,2) AS grade, -- Nota final (calculada o manual via calificador)
            a.grade AS maxgrade, -- Nota máxima del emarking
            a.grademin AS mingrade, -- Nota mínima del emarking
            CASE WHEN d.status < " . EMARKING_STATUS_GRADING . " THEN 0 ELSE 1 end AS attended, -- Indicador de si la nota es null 
            CASE WHEN d.grade >= 4 THEN 1 ELSE 0 end AS pass,
            CASE WHEN d.grade >= 0 AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 1 THEN 1 ELSE 0 end AS histogram_01,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 1  AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 2 THEN 1 ELSE 0 end AS histogram_02,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 2  AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 3 THEN 1 ELSE 0 end AS histogram_03,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 3  AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 4 THEN 1 ELSE 0 end AS histogram_04,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 4  AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 5 THEN 1 ELSE 0 end AS histogram_05,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 5  AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 6 THEN 1 ELSE 0 end AS histogram_06,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 6  AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 7 THEN 1 ELSE 0 end AS histogram_07,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 7  AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 8 THEN 1 ELSE 0 end AS histogram_08,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 8  AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 9 THEN 1 ELSE 0 end AS histogram_09,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 9  AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 10 THEN 1 ELSE 0 end AS histogram_10,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 10  AND d.grade < a.grademin + (a.grade - a.grademin) / 12 * 11 THEN 1 ELSE 0 end AS histogram_11,
            CASE WHEN d.grade >= a.grademin + (a.grade - a.grademin) / 12 * 11 THEN 1 ELSE 0 end AS histogram_12,
            CASE WHEN d.grade - a.grademin < (a.grade - a.grademin) / 3 THEN 1 ELSE 0 end AS rank_1,
            CASE WHEN d.grade - a.grademin >= (a.grade - a.grademin) / 3 AND d.grade - a.grademin  < (a.grade - a.grademin) / 2 THEN 1 ELSE 0 end AS rank_2,
            CASE WHEN d.grade - a.grademin >= (a.grade - a.grademin) / 2  THEN 1 ELSE 0 end AS rank_3,
            c.category AS categoryid,
            cc.name AS categoryname,
            a.id AS emarkingid,
            a.name AS modulename,
            c.fullname AS coursename
            FROM {emarking} AS a
            INNER JOIN {emarking_submission} AS ss ON (a.id = ss.emarking AND a.id in ($emarkingids))
            INNER JOIN {emarking_draft} AS d ON (ss.id = d.submissionid AND d.qualitycontrol=0)
            INNER JOIN {course} AS c ON (a.course = c.id)
            INNER JOIN {course_categories} AS cc ON (c.category = cc.id)
            WHERE d.grade > 0 AND d.status >= " . EMARKING_STATUS_PUBLISHED . "
            ORDER BY a.id asc, d.grade asc) AS G
        GROUP BY categoryid, emarkingid
        with rollup) AS T";

$emarkingstats = $DB->get_recordset_sql($sql);

$sqlcriteria = '
                SELECT
                co.fullname,
                co.id AS courseid,
                s.emarking AS emarkingid,
                a.id AS criterionid,
                a.description,
                ROUND(avg(l.score),1) AS avgscore,
                ROUND(stddev(l.score),1) AS stdevscore,
                ROUND(min(l.score),1) AS minscore,
                ROUND(max(l.score),1) AS maxscore,
                COUNT(DISTINCT s.student) AS count,
                ROUND(avg(l.score)/T.maxscore,1) AS effectiveness,
                T.maxscore AS maxcriterionscore
                FROM {emarking_submission} AS s
                INNER JOIN {emarking_draft} AS d ON (s.emarking IN ('.$emarkingids.') AND d.submissionid = s.id AND d.status >= 20 AND d.qualitycontrol = 0)
                INNER JOIN {emarking_comment} AS ec ON (ec.draft = d.id)
                INNER JOIN {gradingform_rubric_levels} AS l ON (ec.levelid = l.id)
                INNER JOIN {gradingform_rubric_criteria} AS a ON (l.criterionid = a.id)
                INNER JOIN (
                                SELECT
                                s.id AS emarkingid,
                                a.id AS criterionid,
                                MAX(l.score) AS maxscore
                                FROM {emarking} AS s
                                INNER JOIN {grading_definitions} AS d ON (d.id = :definitionid AND s.id IN ('.$emarkingids.'))
                                INNER JOIN {gradingform_rubric_criteria} AS a ON (d.id = a.definitionid)
                                INNER JOIN {gradingform_rubric_levels} AS l ON (a.id = l.criterionid)
                                GROUP BY s.id, criterionid) AS T
                      ON (s.emarking = T.emarkingid AND T.criterionid = a.id)
                INNER JOIN {emarking} AS sg ON (s.emarking = sg.id)
                INNER JOIN {course} AS co ON (sg.course = co.id)
                GROUP BY s.emarking,a.id
                ORDER BY a.sortorder,emarkingid';

$criteriastats = $DB->get_recordset_sql($sqlcriteria, array('definitionid' => $definition->id));

var_dump($definition->id);
var_dump($emarkingids);

$mingrade = 0;
$maxgrade = 0;

$averages = '';

$histogram_courses = '';
$histogram_totals = '';
$histograms = array();
$histograms_totals = array();
$histogramlabels = array();

$pass_ratio = '';

$data = array();
$headers = array();
$headers[] = 'Stats';
$data[0][] = get_string('students');
$data[1][] = get_string('average','mod_emarking');
$data[2][] = get_string('stdev','mod_emarking');
$data[3][] = get_string('min','mod_emarking');
$data[4][] = get_string('quartile1','mod_emarking');
$data[5][] = get_string('median','mod_emarking');
$data[6][] = get_string('quartile3','mod_emarking');
$data[7][] = get_string('max','mod_emarking');
$data[8][] = get_string('lessthan','mod_emarking', 3);
$data[9][] = get_string('between','mod_emarking', array('min'=>3, 'max'=>4));
$data[10][] = get_string('greaterthan','mod_emarking', 4);
foreach($emarkingstats as $stats) {
        if($totalcategories == 1 && !strncmp($stats->seriesname,'SUBTOTAL',8)) {
                continue;
        }
        if($totalemarkings == 1 && !strncmp($stats->seriesname,'TOTAL',5)) {
                continue;
        }
        if(!strncmp($stats->seriesname,'SUBTOTAL',8) || !strncmp($stats->seriesname,'TOTAL',5))
                $histogram_totals .= "'$stats->seriesname',";
        else
                $histogram_courses .= "'$stats->seriesname (N=$stats->students)',";

        for($i=1; $i<=12; $i++) {
                $histogramvalue = '';
                eval("\$histogramvalue = \$stats->histogram_$i;");
                if(!strncmp($stats->seriesname,'SUBTOTAL',8) || !strncmp($stats->seriesname,'TOTAL',5)) {
                        if(!isset($histograms_totals[$i]))
                                $histograms_totals[$i] = $histogramvalue . ',';
                        else
                                $histograms_totals[$i] .= $histogramvalue . ',';
                } else {
                        if(!isset($histograms[$i]))
                                $histograms[$i] = $histogramvalue . ',';
                        else
                                $histograms[$i] .= $histogramvalue . ',';
                }
                if($i % 2 != 0) {
                        if($i <= 6) {
                                $histogramlabels[$i] = '<' . round($stats->mingradeemarking + ($stats->maxgradeemarking - $stats->mingradeemarking) / 12 * $i, 1);
                        } else {
                                $histogramlabels[$i] = '>=' . round($stats->mingradeemarking + ($stats->maxgradeemarking - $stats->mingradeemarking) / 12 * ($i - 1), 1);
                        }
                } else {
                        $histogramlabels[$i] = '';
                }
                // $histogramlabels[$i] = ($stats->mingradeemarking + ($stats->maxgradeemarking - $stats->mingradeemarking) / 12 * $i);
        }

        $mingrade = $stats->mingradeemarking;
        $maxgrade = $stats->maxgradeemarking;
        $averages .= "['$stats->seriesname (N=$stats->students)',$stats->average, $stats->minimum, $stats->maximum],";
        $pass_ratio .= "['$stats->seriesname (N=$stats->students)',$stats->rank_1,$stats->rank_2,$stats->rank_3],";
        $headers[] = $stats->seriesname;
        $data[0][] = $stats->students; 
        $data[1][] = $stats->average; 
        $data[2][] = $stats->stdev;
        $data[3][] = $stats->minimum;
        $data[4][] = $stats->percentile_25; 
        $data[5][] = $stats->percentile_50; 
        $data[6][] = $stats->percentile_75; 
        $data[7][] = $stats->maximum;
        $data[8][] = round($stats->rank_1 * 100, 1)."%"; 
        $data[9][] = round($stats->rank_2 * 100, 1)."%"; 
        $data[10][] = round($stats->rank_3 * 100, 1)."%";
}

$parallels_names_criteria = '';
$effectivenessnum = -1;
$effectiveness[0] = '';
$lastdescription = random_string();
$parallels_ids = array();
foreach($criteriastats as $stats) {
    if(!isset($parallels_ids[$stats->courseid])) {
                $parallels_names_criteria .= "'$stats->fullname (N=$stats->count)',";
                $parallels_ids[$stats->courseid] = $stats->fullname;
        }
        $description = trim(preg_replace('/\s\s+/', ' ', $stats->description));
        $description = preg_replace("/\r?\n/", "\\n", addslashes($description));
        if($lastdescription !== $description) {
                $effectivenessnum++;
                if($effectivenessnum > 0) {
                        $effectiveness[$effectivenessnum-1] .= "]";
                }
                $effectiveness[$effectivenessnum] = "['$description', ";
                $lastdescription = $description;
        }
        $effectiveness[$effectivenessnum] .= $stats->effectiveness . ', ';
}
if($effectivenessnum >= 0) {
        $effectiveness[$effectivenessnum] .= ']';
}
$effectivenessstring = "[\n['".get_string('criterion', 'mod_emarking')."', " . $parallels_names_criteria . "],";
foreach($effectiveness as $effectiverow) {
        $effectivenessstring .= "\n" . $effectiverow . ", ";
}
$effectivenessstring .= " ] ";

$table = new html_table();
$table->attributes['style'] = "width: 100%; text-align:center; font-size:12px;";
$table->head = $headers;
$table->align = array('left', 'center', 'center', 'center', 'center', 'center', 'center', 'center', 'center', 'center', 'center', 'center');
$table->data = $data;
echo $OUTPUT->box_start(null, null, array('style'=>'overflow:scroll'));
echo html_writer::table($table);
echo $OUTPUT->box_end();

if($totalemarkings == 1) {
    $divheight = "height:350px;";
?>
<table border="0" width="100%">
<tr>
<td width="50%"><div id="chart_averages" style="width: 100%; <?php echo $divheight ?>"></div></td>
<td width="50%"><div id="chart_pass_ratio" style="width: 100%;<?php echo $divheight ?>"></div></td>
</tr>
<tr>
<td width="50%"><div id="chart_histogram" style="width: 100%;<?php echo $divheight ?>"></div></td>
<td width="50%"><div id="chart_criteria" style="width: 100%;<?php echo $divheight ?>"></div></td>
</tr>
</table>
<?php
} else {
?>
<div id="chart_averages" style="width: 100%; height: 500px;"></div>
<div id="chart_pass_ratio" style="width: 100%; height: 500px;"></div>
<div id="chart_histogram" style="width: 100%; height: 500px;"></div>
<div id="chart_histogram_totals" style="width: 100%; height: 500px;"></div>
<div id="chart_criteria" style="width: 100%; height: 500px;"></div>
<?php 
}
?>
<script type="text/javascript" src="https://www.google.com/jsapi"></script>
<script type="text/javascript">
          // TODO: Show friendly message when we couldn't load Google's library
      google.load("visualization", "1", {packages:["corechart"]});
      
      google.setOnLoadCallback(drawHistogram);
      <?php if($totalemarkings > 1) { ?>
      google.setOnLoadCallback(drawHistogramTotals);
      <?php } ?>
      google.setOnLoadCallback(drawAverages);
      google.setOnLoadCallback(drawCriteria);
      google.setOnLoadCallback(drawPassRatio);

      // Grades histogram
      function drawHistogram() {
        var data = google.visualization.arrayToDataTable([
          ['Rango', <?php echo $histogram_courses ?>],
          <?php for ($i=1;$i<=12;$i++) { ?>
          ['<?php echo $histogramlabels[$i] ?>',  <?php echo $histograms[$i] ?>],
          <?php } ?>
        ]);

        var options = {
                        animation: {duration: 500},
                        title: '<?php echo get_string('gradehistogram', 'mod_emarking') ?>',
                        hAxis: {title: '<?php echo get_string('range', 'mod_emarking') ?>', titleTextStyle: {color: 'black'}},
                        vAxis: {format:'#'},
                        legend: 'top'
        };

        var chart = new google.visualization.ColumnChart(document.getElementById('chart_pass_ratio'));
        chart.draw(data, options);
                }

      // Pass ratio
      function drawPassRatio() {
                var data = new google.visualization.DataTable();
          data.addColumn({type:'string', role:'domain', label: '<?php echo get_string('course') ?>'}); // Implicit domain label col.
          data.addColumn({type:'number', role:'data', label:'<?php echo get_string('lessthan','mod_emarking', 3) ?>'}); // Implicit series 1 data col.
          data.addColumn({type:'number', role:'data', label:'<?php echo get_string('between','mod_emarking', array('min'=>3, 'max'=>4)) ?>'}); // Implicit series 1 data col.
          data.addColumn({type:'number', role:'data', label:'<?php echo get_string('greaterthan','mod_emarking', 4) ?>'}); // Implicit series 1 data col.
          data.addRows([
                          <?php echo $pass_ratio ?>
          ]);

                var formatter = new google.visualization.NumberFormat({pattern: '#,###.##%'});
                formatter.format(data, 1); // Apply formatter to second column
                formatter.format(data, 2); // Apply formatter to second column
                formatter.format(data, 3); // Apply formatter to second column
                
        var options = {
                        animation: {duration: 500},
                        title: '<?php echo get_string('courseaproval', 'mod_emarking') ?>',
                        vAxis: {title: '<?php echo get_string('course') ?>', titleTextStyle: {color: 'black'}},
                        isStacked: true,
                        series: {0:{color:'red'},1:{color:'#EED1D0'},2:{color:'#57779E'}},
                        hAxis: {format:'#,###%', minValue:0, maxValue:1},
                        legend: 'top'
        };

        var chart = new google.visualization.BarChart(document.getElementById('chart_histogram'));
        chart.draw(data, options);
        }

// Histogram totals
<?php if($totalemarkings > 1) { ?>
      function drawHistogramTotals() {
        var data = google.visualization.arrayToDataTable([
          ['<?php echo get_string('range', 'mod_emarking') ?>', <?php echo $histogram_totals ?>],
          <?php for ($i=1;$i<=12;$i++) { ?>
          ['<?php echo $histogramlabels[$i] ?>',  <?php echo $histograms_totals[$i] ?>],
          <?php } ?>
        ]);

        var options = {
                        animation: {duration: 500},
                        title: '<?php echo get_string('gradehistogramtotal', 'mod_emarking') ?>)',
                        hAxis: {title: '<?php echo get_string('range', 'mod_emarking') ?>', titleTextStyle: {color: 'black'}},
                        seriesType: "bars",
                        series: {2:{type: "line", pointSize: 5}},
                        vAxis: {format:'#'},
                        legend: 'top'
        };

        var chart = new google.visualization.ComboChart(document.getElementById('chart_histogram_totals'));
        chart.draw(data, options);
      }
<?php } ?>

        // Per criteria effectiveness
        function drawCriteria() {
          var data = google.visualization.arrayToDataTable(<?php echo $effectivenessstring ?>);

          var formatter = new google.visualization.NumberFormat({pattern: '#,###.##%'});
          
          <?php for($i=1; $i<=$totalemarkings; $i++) { ?>
                formatter.format(data, <?php echo $i?>); // Apply formatter to second column
          <?php } ?>
          
          var options = {
            title: '<?php echo get_string('criteriaefficiency', 'mod_emarking') ?>',
            vAxis: {title: '<?php echo get_string('criterion', 'mod_emarking') ?>', titleTextStyle: {color: 'black'}},
            hAxis: {format:'#,###%', minValue:0, maxValue:1},
                legend: 'top'
          };

          var chart = new google.visualization.BarChart(document.getElementById('chart_criteria'));
          chart.draw(data, options);
        }

      // Grades average  
      function drawAverages() {
          var data = new google.visualization.DataTable();
          data.addColumn({type:'string', role:'domain', label: '<?php echo get_string('course') ?>'}); // Implicit domain label col.
          data.addColumn({type:'number', role:'data', label:'<?php echo get_string('average', 'mod_emarking') ?>'}); // Implicit series 1 data col.
          data.addColumn({type:'number', role:'interval'}); // Implicit series 1 data col.
          data.addColumn({type:'number', role:'interval'}); // Implicit series 1 data col.
          data.addRows([
                          <?php echo $averages ?>
          ]);
          
          var options = {
                  animation: {duration:500},
                title: '<?php echo get_string('average', 'mod_emarking') ?>',
            legend:'none',
                vAxis: {minValue:<?php echo round($mingrade,0) ?>, maxValue:<?php echo round($maxgrade,0) ?>},
                pointSize: 10,
                legend: 'top'
          };

          var chart = new google.visualization.LineChart(document.getElementById('chart_averages'));
          chart.draw(data, options);
        }
    </script>
<?php 

echo $OUTPUT->footer();