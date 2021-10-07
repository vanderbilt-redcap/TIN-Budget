<?php
namespace Vanderbilt\TINBudget;

class TINBudget extends \ExternalModules\AbstractExternalModule {
	
	public $noSubmitConversionFormNames = [
		"identify_sites",
		"final_decision",
		"piped_data"
	];
	
	public function __construct() {
		parent::__construct();
		
		$pid = $this->getProjectId();
		if (empty($pid)) {
			return;
		}
		$budget_field = $this->getProjectSetting('budget_table_field');
		$gng_field = $this->getProjectSetting('gonogo_table_field');
		$summary_field = $this->getProjectSetting('summary_review_field');
		
		global $Proj;
		if (gettype($Proj) == 'object') {
			// collect instruments that hold schedule, gng, summary fields
			foreach ($Proj->metadata as $field) {
				if ($field['field_name'] === $budget_field) {
					$this->budget_table_instrument = $field['form_name'];
				}
				if ($field['field_name'] === $gng_field) {
					$this->gonogo_table_instrument = $field['form_name'];
				}
				if ($field['field_name'] === $summary_field) {
					$this->summary_review_instrument = $field['form_name'];
				}
				if ($field['field_name'] === 'send_to_sites') {
					$this->send_to_sites_instrument = $field['form_name'];
				}
			}
			
			$this->event_ids = [];
			foreach ($Proj->eventsForms as $eid => $formList) {
				$this->event_ids[] = $eid;
			}
		}
	}
	
	public function getScheduleDataFields() {
		if (!$this->scheduleDataFields) {
			$fields = ['arms', 'proc'];
			for ($arm = 1; $arm <= 5; $arm++) {
				$fields[] = "arm_name_$arm";
				$fields[] = "visits_in_arm_$arm";
				for ($visit = 1; $visit <= 10; $visit++) {
					$suffix = $arm == 1 ? "" : "_$arm";
					$fields[] = "visit$visit$suffix";
				}
			}
			for ($proc = 1; $proc <= 25; $proc++) {
				$fields[] = "procedure$proc";
				$fields[] = "cost$proc";
				$fields[] = "cpt$proc";
			}
			$this->scheduleDataFields = $fields;
		}
		return $this->scheduleDataFields;
	}
	
	public function getScheduleRecordData() {
		if ($this->sched_record_data) {
			return $this->sched_record_data;
		} elseif ($rid = is_numeric($_GET['rid']) ? $_GET['rid'] : null) {
			// fetch, cache, return record data
			$fields = $this->getScheduleDataFields();
			$this->sched_record_data = json_decode(\REDCap::getData('json', $rid, $fields))[0];
		} else {
			throw new \Exception("The TIN Budget module couldn't determine the record ID to fetch record data with");
		}
		return $this->sched_record_data;
	}
	
	public function getArms() {
		$data = $this->getScheduleRecordData();
		$arms = [];
		if ($arm_count = $data->arms) {
			for ($arm_i = 1; $arm_i <= $arm_count; $arm_i++) {
				$arm = (object) [
					"name" => $data->{"arm_name_$arm_i"},
					"visits" => []
				];
				
				$visit_count = $data->{"visits_in_arm_$arm_i"};
				for ($visit_i = 1; $visit_i <= $visit_count; $visit_i++) {
					$suffix = $arm_i == 1 ? "" : "_$arm_i";
					$arm->visits[] = (object) [
						"name" => $data->{"visit" . $visit_i . $suffix}
					];
				}
				
				$arms[] = $arm;
			}
		}
		return $arms;
	}
	
	public function getProcedures() {
		$data = $this->getScheduleRecordData();
		$procedures = [];
		if ($proc_count = $data->proc) {
			for ($proc_i = 1; $proc_i <= $proc_count; $proc_i++) {
				$proc_field_name = "procedure$proc_i";
				$proc_name = $data->$proc_field_name;
				$procedures[] = (object) [
					"name" => $proc_name,
					"cost" => $data->{"cost$proc_i"},
					"cpt" => $data->{"cpt$proc_i"}
				];
			}
		}
		
		return $procedures;
	}
	
	public function getBudgetTableData($record) {
		// get Schedule of Event (CC Budget table) data
		$budget_table_field = $this->getProjectSetting('budget_table_field');
		$params = [
			"records" => $record,
			"fields" => $budget_table_field,
			"return_format" => 'json'
		];
		$data = json_decode(\REDCap::getData($params));
		if (empty($data)) {
			throw new \Exception("The TIN Budget module was unable to retrieve $budget_table_field (configured budget_table_field) data.");
		}
		$budget_table = $data[0]->$budget_table_field;
		return $budget_table;
	}
	
	public function getGoNoGoTableData($record, $instance) {
		// get event ID
		$event_ids = \REDCap::getEventNames();
		$event_id = array_search('Event 1', $event_ids);
		if (empty($event_id)) {
			throw new \Exception("The TIN Budget module couldn't retrieve Go/No-Go data (empty event_id -- is there an 'Event 1' event for this project?)");
		}
		
		$fields = [];
		for ($i = 1; $i <= 25; $i++) {
			$fields[] = "procedure" . $i . "_sc";
			$fields[] = "cost" . $i . "_sc";
		}
		for ($i = 1; $i <= 5; $i++) {
			$fields[] = "arm" . $i . "_decision";
			$fields[] = "arm" . $i . "_comments";
		}
		if (!empty($record)) {
			$get_data_params = [
				"return_format" => 'array',
				"records" => $record,
				"fields" => $fields
			];
			$data = \REDCap::getData($get_data_params);
			
			if (empty($data[$record]['repeat_instances'])) {
				throw new \Exception("The TIN Budget module couldn't extract Go/No-Go table data from retrieved record data (repeat_instances empty)");
			}
			
			$data = $data[$record]['repeat_instances'];
			if (empty($data[$event_id])) {
				throw new \Exception("The TIN Budget module couldn't extract Go/No-Go table data from retrieved record data (repeat_instances[event_id] empty)");
			}
			$data = $data[$event_id][""];
			if (empty($data)) {
				throw new \Exception("The TIN Budget module couldn't extract Go/No-Go table data from retrieved record data ([event_id][\"\"] missing)");
			}
			
			$data = $data[$instance];
			
			if (empty($data)) {
				throw new \Exception("The TIN Budget module couldn't extract Go/No-Go table data from retrieved record data (no instance data found)");
			}
			
			return $data;
		} else {
			throw new \Exception("The TIN Budget module couldn't determine the record ID to fetch Go/No-Go table data with");
		}
	}
	
	public function getSummaryReviewData($record, $instance) {
		if (empty($instance))
			$instance = 1;
		$data = new \stdClass();
		$field_list = ['institution'];
		for ($i = 1; $i <= 5; $i++) {
			$field_list[] = "fixedcost$i";
			$field_list[] = "fixedcost$i" . "_detail";
			$field_list[] = "fixedcost$i" . "_decision";
			$field_list[] = "fixedcost$i" . "_comments";
			$field_list[] = "arm$i" . "_decision";
			$field_list[] = "arm$i" . "_comments";
		}
		
		$rc_data = \REDCap::getData('array', $record, $field_list);
		
		// collate field data, prioritizing target instance data set
		$event_id = array_key_first($rc_data[$record]);
		$base_data = $rc_data[$record][$event_id];
		$instance_data = $rc_data[$record]['repeat_instances'];
		$instance_data = reset($instance_data);
		$instance_data = reset($instance_data);
		$instance_data = $instance_data[$instance];
		foreach ($field_list as $i => $field_name) {
			if (!empty($base_data[$field_name])) {
				$data->$field_name = $base_data[$field_name];
			}
			if (!empty($instance_data[$field_name])) {
				$data->$field_name = $instance_data[$field_name];
			}
		}
		
		// convert raw->label for ..._decision fields
		foreach ($data as $name => $value) {
			if (strpos($name, '_decision') !== false) {
				$labels = $this->getChoiceLabels($name);
				$data->$name = $labels[$value];
			}
		}
		
		return $data;
	}
	
	public function getReconciliationData() {
		// get event ID
		$event_ids = \REDCap::getEventNames();
		$event_id = array_search('Event 1', $event_ids);
		$fields = [
			"institution",
			"institution_ctsa_name",
			"institution_non_ctsa_name",
			"affiliate_ctsa_institution",
			"budget_request_date",
			"consideration",
			"final_gonogo",
			"final_gonogo_comments",
		];
		
		$params = [
			"fields" => $fields,
			"events" => $event_id,
			"exportAsLabels" => true
		];
		$records = \REDCap::getData('array', null, $fields, $event_id);
		if (!$records) {
			return "REDCap couldn't get institution data at this time.";
		}
		
		// tabulate
		$table_data = [];
		
		foreach ($records as $record) {
			$site_array = $record['repeat_instances'][$event_id][''];
			foreach($site_array as $site) {
				$row = [];
				
				// determine site name
				if ($site['institution'] == 500) {
					$row['name'] = $site['affiliate_ctsa_institution'] . " Affiliate: " . $site['institution_ctsa_name'];
				} elseif ($site['institution'] == 999) {
					$row['name'] = "Non-CTSA Site: " . $site['institution_non_ctsa_name'];
				} else {
					$row['name'] = "N/A";
				}
				
				$row['date_of_request'] = $site['budget_request_date'];
				
				if ($site['consideration'] == '0') {
					$row['date_of_response'] = 'Talk to Clint';
				} elseif ($site['consideration'] == '1') {
					$row['date_of_response'] = 'Talk to Clint or use Go/No-Go pending field';
				} else {
					$row['date_of_response'] = "N/A";
				}
				
				$row['decision'] = 'N/A';
				if ($site['final_gonogo'] == '1') {
					$row['decision'] = 'Go';
				} elseif ($site['final_gonogo'] == '2') {
					$row['decision'] = 'No-Go';
				} elseif ($site['final_gonogo'] == '3') {
					$row['decision'] = 'Need More Info';
				}
				$row['decision_comments'] = $site['final_gonogo_comments'];
				
				$table_data[] = $row;
			}
		}
		
		return $table_data;
	}
	
	public function getCCSummaryData() {
		$data = [];
		$record = '1';
		
		// get STUDY INTAKE FORM data
		$fields_needed = [
			"cc_contact_person_fn",
			"cc_contact_person_ln",
			"cc_email",
			"cc_phone_number",
			"protocol_synopsis",
			"brief_stud_description",
			"prop_summary_describe2_5f5",
			"number_subjects",
			"study_population",
			"number_sites",
			"funding_source",
			"funding_mechanism",
			"funding_other",
			"institute_center",
			"grant_app_no",
			"funding_opp_announcement",
			"anticipated_budget",
			"funding_duration",
			"reci_date",
			"site_active_date",
			"support_date",
		];
		
		for ($i = 1; $i <= 5; $i++) {
			$fields_needed[] = "fixedcost$i";
			$fields_needed[] = "fixedcost$i" . "_detail";
		}
		
		$getDataParams = [
			"project_id" => $this->getProjectId(),
			"return_format" => "array",
			"records" => $record,
			"fields" => $fields_needed
		];
		$study_intake_data = \REDCap::getData($getDataParams);
		
		$data = reset($study_intake_data[$record]);
		
		return $data;
	}
	
	public function showStaticScheduleArms($budget_data) {
		foreach($budget_data->arms as $arm_i => $arm) {
		?>
		<div class="budget_container">
		<h6><?= "Arm " . ($arm_i + 1) . ": " . ($arm->name ?? "") ?></h6>
		<table>
			<thead>
				<tr>
				<?php
				foreach ($arm->visits as $visit_i => $visit) {
					if (empty($visit)) {
						echo "<th></th>";
					} else {
						echo "<th>Visit " . ($visit_i + 1) . ": " . $visit->name . "</th>";
					}
				}
				?>
				</tr>
			</thead>
			<tbody>
			<?php
			$visit1 = $arm->visits[1];
			$row_count = count($visit1->procedure_counts);
			for ($row_i = 1; $row_i <= $row_count; $row_i++) {
				$row_i_0 = $row_i - 1;
				?>
				<tr>
					<td><?php
					foreach($arm->procedureRows as $procedure) {
						if ($procedure->index == $visit1->procedure_counts[$row_i_0]->procedure_index) {
							echo $procedure->name;
							break;
						}
					}
					?></td>
					<?php
					foreach ($arm->visits as $visit_i => $visit) {
						if (!empty($visit)) {
							if ($visit->procedure_counts[$row_i_0]->count != 0) {
								$class = " class='nonzero'";
							} else {
								$class = "";
							}
							echo "<td$class>" . $visit->procedure_counts[$row_i_0]->count . "</td>";
						}
					}
					?>
				</tr>
				<?php
			}
			
			// add Total $$ row
			echo "<tr><td class='no-border'>Total $$</td>";
			foreach ($arm->visits as $visit_i => $visit) {
				if (!empty($visit)) {
					echo "<td>" . $visit->total . "</td>";
				}
			}
			echo "</tr>";
			?>
			</tbody>
		</table>
		</div>
		<?php
		}
	}
	
	public function showSummarySiteTable($record) {
		$fields = [];
		for ($i = 1; $i <= 50; $i++) {
			$fields[] = "name$i";
			$fields[] = "institution$i";
			$fields[] = "email$i";
			$fields[] = "zip$i";
		}
		$pid = $this->getProjectId();
		$proj = new \Project($pid);
		$eid = $proj->firstEventId;
		$params = [
			"project_id" => $pid,
			"return_format" => "array",
			"records" => $record,
			"fields" => $fields,
			"events" => $eid
		];
		
		$site_data = \REDCap::getData($params);
		$site_data = $site_data[$record][$eid];
		?>
		<div>
			<table class='sites_contact_info'>
				<thead>
					<tr>
						<th></th>
						<th>Name of Contact</th>
						<th>Institution Name</th>
						<th>Email Address</th>
						<th>Zip Code</th>
					</tr>
				</thead>
				<tbody>
				<?php
				for ($i = 1; $i <= 50; $i++) {
					?>
					<tr>
						<td><?= "Site $i" ?></td>
						<td><?= $site_data["name$i"] ?></td>
						<td><?= $site_data["institution$i"] ?></td>
						<td><?= $site_data["email$i"] ?></td>
						<td><?= $site_data["zip$i"] ?></td>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>
		</div>
		<?php
	}
	
	public function getCCSummaryHTML($cc_data) {
		if (empty($cc_data)) {
			throw new \Exception("Tried to output CC Summary Review page, but argument \$cc_data is empty.");
		}
		
		$record = '1';
		$budget_data = $this->getBudgetTableData($record);
		if (empty($budget_data)) {
			throw new \Exception("Tried to output CC Summary Review page, but argument \$cc_data is empty.");
		} else {
			$budget_data = json_decode($budget_data);
		}
		
		?>
		<button type="button" class="btn btn-primary" style="margin: 8px;" onclick="window.print()">Print</button>
		<div id="cc_summary">
		<h3>BUDGET FEASIBILITY SUMMARY PAGE FOR COORDINATING CENTER</h3>
		
		<!--STUDY INTAKE FORM-->
		<div>
		<h5 class="table_title"><u>STUDY INTAKE FORM</u></h5>
		<table class="cc_rev_table blue_table_headers">
			<thead>
				<tr>
					<th>COORDINATING CENTER/STUDY INFORMATION</th>
					<th>INFORMATION PROVIDED</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>Coordinating Center Contact Information</td>
					<td><?= $cc_data['cc_contact_person_fn'] . ' ' . $cc_data['cc_contact_person_ln'] . '; ' . $cc_data['cc_email'] . '; ' . $cc_data['cc_phone_number'] ?></td>
				</tr>
				<tr>
					<td>Short study name Protocol/protocol synopsis for this study</td>
					<td></td><!-- TODO: make link to download edoc or say no file attached -->
				</tr>
				<tr>
					<td>Brief Study Description</td>
					<td><?= $cc_data['brief_stud_description'] ?></td>
				</tr>
				<tr>
					<td>Description of Study Intervention</td>
					<td><?= $cc_data['prop_summary_describe2_5f5'] ?></td>
				</tr>
				<tr>
					<td>Enrollment Goals</td>
					<td>
						Estimated number of subjects: <?= $cc_data['number_subjects'] ?><br>
						Study Population: <?= $cc_data['study_population'] ?><br>
						Estimated number of sites: <?= $cc_data['number_sites'] ?>
					</td>
				</tr>
				<tr>
					<td>Funding/Support for the Proposal</td>
					<td>Current funding source: <?= $cc_data['funding_source'] ?><br>
						Funding mechanism: <?= $cc_data['funding_mechanism'] ?? $cc_data['funding_other'] ?><br>
						Identified I/C: <?= $cc_data['institute_center'] ?><br>
						Grant/application number: <?= $cc_data['grant_app_no'] ?><br>
						FOA (if applicable): <?= $cc_data['funding_opp_announcement'] ?><br>
						Anticipated total budget (direct and indirect): <?= $cc_data['anticipated_budget'] ?><br>
						Total duration of funding period: <?= $cc_data['funding_duration'] ?><br>
						Anticipated funding start date for application: <?= $cc_data['reci_date'] ?>
					</td>
				</tr>
				<tr>
					<td>Timelines</td>
					<td>Date planned for first site activated: <?= $cc_data['site_active_date'] ?><br>
						Anticipated start date for initiation of funding: <?= $cc_data['support_date'] ?>
					</td>
				</tr>
			</tbody>
		</table>
		</div>
		
		<!--FIXED COSTS SUMMARY REVIEW-->
		<div class='pba'>
		<h5 class="table_title"><u>FIXED COSTS SUMMARY REVIEW</u></h5>
		<table class="cc_rev_table blue_table_headers">
			<thead>
				<tr>
					<th>FIXED COST</th>
					<th>FIXED COST DETAIL</th>
				</tr>
			</thead>
			<tbody>
			<?php
			for ($i = 1; $i <= 5; $i++) {
				echo "<tr><td>" . $cc_data["fixedcost$i"] . "</td><td>" . $cc_data["fixedcost$i" . "_detail"] . "</td></tr>";
			}
			?>
			</tbody>
		</table>
		</div>
		
		<!--PROCEDURE COSTS SUMMARY REVIEW-->
		<div class='pbb pba'>
		<h5 class="table_title"><u>PROCEDURE COSTS SUMMARY REVIEW</u></h5>
		<table class="cc_rev_table green_table_headers">
			<thead>
				<tr>
					<th>Procedure Name</th>
					<th>Associated CPT Code</th>
					<th>Reimbursement Amount</th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach($budget_data->procedures as $i => $info) {
				echo "<tr><td>" . $info->name . "</td><td>" . $info->cpt . "</td><td>" . $info->cost . "</td></tr>";
			}
			?>
			</tbody>
		</table>
		</div>
		
		<!--SCHEDULE OF EVENTS REVIEW-->
		<div class='pbb pba'>
		<h5 class="table_title"><u>SCHEDULE OF EVENTS REVIEW</u></h5>
		<?php $this->showStaticScheduleArms($budget_data); ?>
		</div>
		
		<!--IDENTIFIED SITES REVIEW-->
		<div class='pbb pba extra-top-space'>
		<h5 class="table_title"><u>IDENTIFIED SITES REVIEW</u></h5>
		<?php $this->showSummarySiteTable($record); ?>
		</div>
		
		</div>
		<script type="text/javascript">
			$().ready(function() {
				$('head').append('<link rel="stylesheet" href="<?= $this->getUrl("css/cc_summary.css"); ?>">');
			});
		</script>
		<?php
	}
	
	public function replaceScheduleFields($record) {
		$_GET['rid'] = $record;
		
		// retrieve name of configured budget table field
		$schedule_field = $this->getProjectSetting('budget_table_field');
		
		// start buffering to catch getBudgetTable output (html)
		ob_start();
		include('php/getBudgetTable.php');
		// escape quotation marks
		$budget_table = addslashes(ob_get_contents());
		ob_end_clean();
		// remove newlines and tabs
		$budget_table = str_replace(array("\n", "\r", "\t"), '', $budget_table);
		// we didn't use json_encode above because getBudgetTable.php outputs HTML, not JSON
		
		// get existing schedule_of_events_json (contains procedure counts)
		$data_params = [
			"project_id" => $this->getProjectId(),
			"return_format" => "array",
			"records" => "$record",
			"fields" => $schedule_field
		];
		$soe_data = \REDCap::getData($data_params)[$record];
		$soe_data = reset($soe_data);
		if (!empty($soe_data[$schedule_field])) {
			$soe_data = $soe_data[$schedule_field];
		} else {
			unset($soe_data);
		}
		
		$cpt_endpoint_url = $this->getProjectSetting('cpt_endpoint_url');
		
		?>
		<script type="text/javascript">
			TINBudget = {
				budget_css_url: '<?= $this->getUrl('css/budget.css'); ?>',
				cpt_endpoint_url: '<?= $cpt_endpoint_url; ?>',
				procedures_json: '<?= json_encode($procedures) ?>'
			}
			
			TINBudgetSurvey = {
				schedule_field: "<?= $schedule_field; ?>",
				budget_table: "<?=$budget_table;?>",
				soe_json: '<?=$soe_data;?>',
				updateScheduleField: function(scheduleString) {
					var field_name = TINBudgetSurvey.schedule_field;
					$("textarea[name='" + field_name + "']").val(scheduleString);
				}
			}
			
			$(document).ready(function() {
				if (TINBudget.procedures_json.length > 0) {
					TINBudget.procedures = JSON.parse(TINBudget.procedures_json);
				}
				if (TINBudgetSurvey.soe_json.length > 0) {
					TINBudgetSurvey.soe_data = JSON.parse(TINBudgetSurvey.soe_json);
				}
				var fieldname = TINBudgetSurvey.schedule_field;
				$('#' + fieldname + '-tr').before("<div id='budgetTable'>" + TINBudgetSurvey.budget_table + "</div>")
				$('#' + fieldname + '-tr').hide();
			});
		</script>
		<script type='text/javascript' src='<?= $this->getUrl('js/budget.js'); ?>'></script>
		<?php
	}
	
	public function replaceGoNoGoFields($record, $instance) {
		// get budget table data
		$budget_table = $this->getBudgetTableData($record);
		
		// get Go/No-Go table data and field name
		$gonogo_table_data = json_encode($this->getGoNoGoTableData($record, $instance));
		$gonogo_table_field = $this->getProjectSetting('gonogo_table_field');
		$save_arm_fields_url = $this->getUrl('php/saveArmFields.php');
		?>
		<script type="text/javascript">
			TINGoNoGo = {
				record_id: '<?= $record; ?>',
				instance: '<?= $instance; ?>',
				gng_field: '<?= $gonogo_table_field; ?>',
				schedule: JSON.parse('<?= $budget_table; ?>'),
				gng_data: JSON.parse('<?= $gonogo_table_data; ?>'),
				css_url: "<?= $this->getUrl('css/gonogo.css'); ?>",
				save_arm_fields_url: '<?= $save_arm_fields_url; ?>'
			}
		</script>
		<script type='text/javascript' src='<?= $this->getUrl('js/gonogo.js'); ?>'></script>
		<?php
	}
	
	public function replaceSummaryReviewField($record, $instance) {
		// get budget table data
		$budget_table = $this->getBudgetTableData($record);
		
		// get Go/No-Go table data and field name
		$gonogo_table_data = json_encode($this->getGoNoGoTableData($record, $instance));
		
		$summary_review_field = $this->getProjectSetting('summary_review_field');
		
		$summary_review_data = $this->getSummaryReviewData($record, $instance);
		$summary_review_data = json_encode($summary_review_data, JSON_HEX_APOS);
		$summary_review_data = addslashes($summary_review_data);
		
		$gonogo_table_field = json_encode($this->getProjectSetting('gonogo_table_field'));
		$save_arm_fields_url = $this->getUrl('php/saveArmFields.php');
		?>
		<script type="text/javascript">
			TINSummary = {
				record_id: '<?= $record; ?>',
				instance: '<?= $instance; ?>',
				summary_review_field: '<?= $summary_review_field; ?>',
				summary_data: JSON.parse('<?= $summary_review_data; ?>'),
				schedule: JSON.parse('<?= $budget_table; ?>'),
				gng_data: JSON.parse('<?= $gonogo_table_data; ?>'),
				css_url: "<?= $this->getUrl('css/summary.css'); ?>"
			}
		</script>
		<script type='text/javascript' src='<?= $this->getUrl('js/summary.js'); ?>'></script>
		<?php
	}
	
	public function replaceCCSummaryReviewField($record, $instance) {
		$cc_html_url = $this->getUrl("cc_summary.php");
		$cc_replace_field = "summary_review_upload";
		?>
		<script type="text/javascript">
			TINSummary = {
				record_id: '<?= $record; ?>',
				instance: '<?= $instance; ?>',
				cc_summary_review_field: '<?= $cc_replace_field; ?>',
				cc_html_url: '<?= $cc_html_url; ?>'
			}
			$(document).ready(function() {
				if (typeof TINSummary.cc_summary_review_field == 'string') {
					$('#' + TINSummary.cc_summary_review_field + '-tr').hide();
					$('#surveyinstructions').after("<div id='cc_summary_review'></div>");
					// $("#cc_summary_review_td").append("<div id='cc_summary_review'></div>");
					$.ajax(TINSummary.cc_html_url).done(function(data) {
						var div = $("div#cc_summary_review");
						div.html(data);
					});
				}
			});
		</script>
		<?php
	}
	
	private function renameSubmitForSurvey() {
		?>
		<script type="text/javascript">
			$(document).ready(function() {
				$("button[name='submit-btn-saverecord']").html("Next")
			});
		</script>
		<?php
	}
	
	private function addDownloadProcedureResourceButton($record, $event_id, $repeat_instance) {
		$dl_proc_wb_url = $this->getUrl("php/downloadProceduresWorkbook.php") . "&record=$record&event_id=$event_id&instance=$repeat_instance";
		?>
		<script type="text/javascript">
			$(document).ready(function() {
				var link = "<a href='<?= $dl_proc_wb_url ?>'>Download Study Procedure List <i class='fas fa-download'></i></a>";
				$("#future_procedure_file-tr span").replaceWith(link)
			});
		</script>
		<?php
	}
	
	public function downloadProceduresWorkbook($record_id, $event_id, $instance) {
		// get procedure info for given record_id
		$procedure_fields = [];
		for ($i = 1; $i <= 25; $i++) {
			$procedure_fields[] = "procedure$i";
			$procedure_fields[] = "cpt$i";
		}
		$study_short_name_field = "short_name";
		$procedure_fields[] = $study_short_name_field;
		
		$params = [
			"project_id" => $this->getProjectId(),
			"return_format" => "array",
			"records" => $record_id,
			// "events" => $event_id,
			"fields" => $procedure_fields
		];
		$data = \REDCap::getData($params);
		global $Proj;
		$record = $data[$record_id][$Proj->firstEventId];
		$ri = $data[$record_id]["repeat_instances"];
		$ri = reset($ri);
		$ri = reset($ri);
		$ri = $ri[$instance];
		foreach ($ri as $field=>$value) {
			if (empty($record[$field])) {
				$record[$field] = $value;
			}
		}
		
		// create workbook from template in module directory
		$module_path = $this->getModulePath();
		require "$module_path" . "vendor/autoload.php";
		$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader("Xlsx");
		$workbook = $reader->load($module_path . "templates/procedures.xlsx");
		$sheet = $workbook->getActiveSheet();
		
		// update study name in wb first cell
		$study_name = $record[$study_short_name_field] ?? "<study name>";
		$sheet->setCellValue("A1", "All Procedures for $study_name");
		
		// update workbook cells
		for ($i = 1; $i <= 25; $i++) {
			$name = $record["procedure$i"];
			$cpt = $record["cpt$i"];
			
			$sheet->setCellValue("B" . ($i + 2), $name);
			$sheet->setCellValue("C" . ($i + 2), $cpt);
		}
		
		// download workbook to user
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="Procedures.xlsx"');
		header('Cache-Control: max-age=0');
		$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($workbook, 'Xlsx');
		$writer->save('php://output');
	}
	
	private function createSiteInstances($record_id) {
		carl_log("calling TINBudget::createSiteInstances($record_id)", true);
		// this function is called upon completing the survey containing [send_to_sites] (if [send_to_sites] === '1' for the associated record)
		$parameters = [
			"project_id" => $this->getProjectId(),
			"return_format" => 'array',
			"records" => $record_id,
			"fields" => 'eoi'
		];
		$data = \REDCap::getData($parameters);
		$eoi_count = intval(reset($data[$record_id])['eoi']);
		$log_message = "Attempting to create site instances upon submission of survey containing [sites_to_send] field:\n";
		
		if ($eoi_count > 0) {
			$instances = [];
			for ($site_index = 1; $site_index <= $eoi_count; $site_index++) {
				$instance = new \stdClass();
				$primary_key_name = $this->getRecordIdField();
				$instance->$primary_key_name = "$record_id";
				$instance->redcap_repeat_instance = $site_index;
				$instance->redcap_event_name = "event_1_arm_1";
				// if Michelle doesn't want to fill eoi_instance, maybe set budget_review_and_feasibility_complete = '0'?
				$instance->eoi_instance = $site_index;
				$instances[] = $instance;
			}
			
			$payload = json_encode($instances);
			$parameters = [
				"project_id" => $this->getProjectId(),
				"dataFormat" => 'json',
				"data" => $payload
			];
			$result = \REDCap::saveData($parameters);
			
			if (!empty($result['errors'])) {
				$log_message .= "FAILURE\nThe [eoi] field for record '$record_id' is > 0 but there was an error saving the data:\n";
				$log_message .= "\\REDCap::saveData return array ['errors']:\n" . print_r($result['errors'], true) . "\n";
				// $log_message .= "\\REDCap::saveData data argument given:\n" . print_r($payload, true);
			} else {
				// verify we have more than [eoi] instances of 'event_1_arm_1' in this record
				$parameters = [
					"project_id" => $this->getProjectId(),
					"return_format" => 'json',
					"records" => $record_id,
					"fields" => 'proposal_id'
				];
				$data = json_decode(\REDCap::getData($parameters));
				$sum = 0;
				foreach ($data as $instance) {
					if ($instance->redcap_event_name == 'event_1_arm_1')
						$sum++;
				}
				if ($sum > $eoi_count) {
					$log_message .= "SUCCESS\nRecord '$record_id' has at least $eoi_count instances of event 'Event 1'";
				} else {
					$log_message .= "FAILURE\n\\REDCap::saveData returned no errors, but the module failed to verify the creation of $eoi_count new 'Event 1' instances.";
				}
			}
		} else {
			$log_message .= "FAILURE\nThe [eoi] field for record '$record_id' is not > 0.";
		}
		
		\REDCap::logEvent("TIN Budget Module", $log_message);
	}
	
	public function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		// replace schedule of event field in survey page with generated table
		if ($instrument == $this->budget_table_instrument) {
			$this->replaceScheduleFields($record);
		}
		
		// replace Go/No-Go field in survey page with generated table
		if ($instrument == $this->gonogo_table_instrument) {
			$this->replaceGoNoGoFields($record, $repeat_instance);
		} 
		
		// replace Summary Review field in survey page with interface
		if ($instrument == $this->summary_review_instrument) {
			$this->replaceSummaryReviewField($record, $repeat_instance);
		}
		
		if (!in_array($instrument, $this->noSubmitConversionFormNames)) {
			$this->renameSubmitForSurvey();
		}
		
		if ($instrument == 'enter_cost_to_run_procedure') {
			$this->addDownloadProcedureResourceButton($record, $event_id, $repeat_instance);
		}
		
		if ($instrument == 'summary_review_page') {
			$this->replaceCCSummaryReviewField($record, $repeat_instance);
		}
	}
	
	public function redcap_survey_complete($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		if ($instrument == $this->send_to_sites_instrument) {
			$parameters = [
				"project_id" => $project_id,
				"return_format" => 'json',
				"records" => $record,
				"fields" => 'send_to_sites'
			];
			$data = json_decode(\REDCap::getData($parameters));
			carl_log("\$getData: " . print_r($data, true));
			if ($data[0]->send_to_sites === '1') {
				$this->createSiteInstances($record);
			}
		}
	}

}