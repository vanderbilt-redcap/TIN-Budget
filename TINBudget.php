<?php
namespace Vanderbilt\TINBudget;

class TINBudget extends \ExternalModules\AbstractExternalModule {
	
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
				$proc_names = $this->getChoiceLabels("procedure$proc_i");
				$procedures[] = (object) [
					"name" => $proc_names[$data->{"procedure$proc_i"}],
					"cost" => $data->{"cost$proc_i"}
				];
			}
		}
		
		return $procedures;
	}
	
	public function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		$_GET['rid'] = $record;
		$schedule_fields = json_encode($this->getProjectSetting('sched_field_name'));
		ob_start();
		include('php/getBudgetTable.php');
		// escape quotation marks
		$budget_table = addslashes(ob_get_contents());
		ob_end_clean();
		// escape newlines to make this a multi-line string in js
		$budget_table = str_replace(array("\r\n", "\n", "\r"), '\\n', $budget_table);
		carl_log('hi');
		?>
		<script type="text/javascript">
			TINBudgetSurvey = {
				schedule_fields: JSON.parse('<?= $schedule_fields; ?>'),
				budget_table: "<?=$budget_table;?>",
				updateScheduleFields: function(scheduleString) {
					for (var field_i in TINBudgetSurvey.schedule_fields) {
						var field_name = TINBudgetSurvey.schedule_fields[field_i];
						// console.log("inputs: ", $("input[name='" + field_name + "']"));
						$("input[name='" + field_name + "']").val(scheduleString);
					}
				}
			}
		</script>
		<script type="text/javascript" src="<?= $this->getUrl('js/survey_page.js'); ?>"></script>
		<script type='text/javascript'>
			TINBudget = {
				budget_css_url: '<?= $module->getUrl('css/budget.css'); ?>'
			}
			TINBudget.procedures = JSON.parse('<?= json_encode($procedures) ?>')
		</script>
		<script type='text/javascript' src='<?= $module->getUrl('js/budget.js'); ?>'></script>
		<?php
	}
}