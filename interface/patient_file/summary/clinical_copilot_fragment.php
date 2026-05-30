<?php

/**
 * AgentForge Clinical Co-Pilot dashboard panel.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

if (empty($pid) || empty($thisauth)) {
    return;
}

$copilotPid = (int) $pid;
$copilotCanReadProblems = \OpenEMR\Common\Acl\AclMain::aclCheckIssue('medical_problem');
$copilotCanReadMedications = \OpenEMR\Common\Acl\AclMain::aclCheckIssue('medication');
$copilotCanReadAllergies = \OpenEMR\Common\Acl\AclMain::aclCheckIssue('allergy');
$copilotCanReadLabs = \OpenEMR\Common\Acl\AclMain::aclCheckCore('patients', 'lab');

$copilotEncounterRow = sqlQuery(
    "SELECT COUNT(*) AS count, MAX(`date`) AS last_date FROM form_encounter WHERE pid = ?",
    [$copilotPid]
);
$copilotEncounterCount = (int) ($copilotEncounterRow['count'] ?? 0);
$copilotLastEncounterDate = $copilotEncounterRow['last_date'] ?? '';

$copilotProblemsCount = null;
if ($copilotCanReadProblems) {
    $copilotProblemsRow = sqlQuery(
        "SELECT COUNT(*) AS count FROM lists WHERE pid = ? AND type = ? AND " . dateEmptySql('enddate'),
        [$copilotPid, 'medical_problem']
    );
    $copilotProblemsCount = (int) ($copilotProblemsRow['count'] ?? 0);
}

$copilotMedicationsCount = null;
if ($copilotCanReadMedications) {
    $copilotMedicationsRow = sqlQuery(
        "SELECT COUNT(*) AS count FROM lists WHERE pid = ? AND type = ? AND " . dateEmptySql('enddate'),
        [$copilotPid, 'medication']
    );
    $copilotMedicationsCount = (int) ($copilotMedicationsRow['count'] ?? 0);
}

$copilotAllergiesCount = null;
if ($copilotCanReadAllergies) {
    $copilotAllergiesRow = sqlQuery(
        "SELECT COUNT(*) AS count FROM lists WHERE pid = ? AND type = ? AND " . dateEmptySql('enddate'),
        [$copilotPid, 'allergy']
    );
    $copilotAllergiesCount = (int) ($copilotAllergiesRow['count'] ?? 0);
}

$copilotLabsCount = null;
if ($copilotCanReadLabs) {
    $copilotLabsRow = sqlQuery(
        "SELECT COUNT(*) AS count FROM procedure_result AS pr " .
        "JOIN procedure_report AS prep ON prep.procedure_report_id = pr.procedure_report_id " .
        "JOIN procedure_order AS po ON po.procedure_order_id = prep.procedure_order_id " .
        "WHERE po.patient_id = ?",
        [$copilotPid]
    );
    $copilotLabsCount = (int) ($copilotLabsRow['count'] ?? 0);
}

$copilotRestrictedText = xl('Restricted by access controls');
?>
<div class="row">
    <div class="col-12 p-1">
        <section class="card mx-1 mb-2" aria-labelledby="clinical-copilot-heading">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
                <div>
                    <h2 id="clinical-copilot-heading" class="h5 mb-0"><?php echo xlt('Clinical Co-Pilot'); ?></h2>
                    <small class="text-muted"><?php echo xlt('Pre-visit brief'); ?></small>
                </div>
                <span class="badge badge-secondary"><?php echo xlt('Read only'); ?></span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-7">
                        <h3 class="h6"><?php echo xlt('Draft status'); ?></h3>
                        <p class="mb-3">
                            <?php echo xlt('AI summary pending configuration. The panel is connected to the current patient context and access checks only.'); ?>
                        </p>
                        <div class="d-flex flex-wrap">
                            <span class="badge badge-light border mr-2 mb-2"><?php echo xlt('Patient demographics'); ?></span>
                            <span class="badge badge-light border mr-2 mb-2"><?php echo xlt('Encounters'); ?></span>
                            <?php if ($copilotCanReadProblems) : ?>
                                <span class="badge badge-light border mr-2 mb-2"><?php echo xlt('Problem list'); ?></span>
                            <?php endif; ?>
                            <?php if ($copilotCanReadMedications) : ?>
                                <span class="badge badge-light border mr-2 mb-2"><?php echo xlt('Medications'); ?></span>
                            <?php endif; ?>
                            <?php if ($copilotCanReadAllergies) : ?>
                                <span class="badge badge-light border mr-2 mb-2"><?php echo xlt('Allergies'); ?></span>
                            <?php endif; ?>
                            <?php if ($copilotCanReadLabs) : ?>
                                <span class="badge badge-light border mr-2 mb-2"><?php echo xlt('Labs'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <h3 class="h6"><?php echo xlt('Available context'); ?></h3>
                        <dl class="row mb-0">
                            <dt class="col-sm-7"><?php echo xlt('Encounters'); ?></dt>
                            <dd class="col-sm-5"><?php echo text($copilotEncounterCount); ?></dd>

                            <dt class="col-sm-7"><?php echo xlt('Last encounter'); ?></dt>
                            <dd class="col-sm-5">
                                <?php echo !empty($copilotLastEncounterDate) ? text(oeFormatShortDate($copilotLastEncounterDate)) : xlt('None'); ?>
                            </dd>

                            <dt class="col-sm-7"><?php echo xlt('Active problems'); ?></dt>
                            <dd class="col-sm-5"><?php echo $copilotProblemsCount === null ? text($copilotRestrictedText) : text($copilotProblemsCount); ?></dd>

                            <dt class="col-sm-7"><?php echo xlt('Active medications'); ?></dt>
                            <dd class="col-sm-5"><?php echo $copilotMedicationsCount === null ? text($copilotRestrictedText) : text($copilotMedicationsCount); ?></dd>

                            <dt class="col-sm-7"><?php echo xlt('Active allergies'); ?></dt>
                            <dd class="col-sm-5"><?php echo $copilotAllergiesCount === null ? text($copilotRestrictedText) : text($copilotAllergiesCount); ?></dd>

                            <dt class="col-sm-7"><?php echo xlt('Lab results'); ?></dt>
                            <dd class="col-sm-5"><?php echo $copilotLabsCount === null ? text($copilotRestrictedText) : text($copilotLabsCount); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
