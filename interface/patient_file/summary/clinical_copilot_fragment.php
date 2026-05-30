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
        <section
            class="card mx-1 mb-2"
            aria-labelledby="clinical-copilot-heading"
            data-clinical-copilot-endpoint="<?php echo attr($GLOBALS['webroot'] . '/interface/patient_file/summary/clinical_copilot.php'); ?>"
            data-clinical-copilot-pid="<?php echo attr($copilotPid); ?>"
            data-clinical-copilot-csrf="<?php echo attr(\OpenEMR\Common\Csrf\CsrfUtils::collectCsrfToken(session: $session)); ?>"
        >
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
                <div>
                    <h2 id="clinical-copilot-heading" class="h5 mb-0"><?php echo xlt('Clinical Co-Pilot'); ?></h2>
                    <small class="text-muted"><?php echo xlt('Pre-visit brief'); ?></small>
                </div>
                <div class="d-flex align-items-center">
                    <span class="badge badge-secondary mr-2" data-clinical-copilot-status><?php echo xlt('Read only'); ?></span>
                    <button
                        type="button"
                        class="btn btn-sm btn-secondary"
                        title="<?php echo xla('Refresh Clinical Co-Pilot context'); ?>"
                        data-clinical-copilot-refresh
                    >
                        <i class="fa fa-sync" aria-hidden="true"></i>
                        <span class="sr-only"><?php echo xlt('Refresh'); ?></span>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-7">
                        <h3 class="h6"><?php echo xlt('Draft status'); ?></h3>
                        <p class="mb-3" data-clinical-copilot-summary>
                            <?php echo xlt('AI summary pending configuration. The panel is connected to the current patient context and access checks only.'); ?>
                        </p>
                        <div class="d-flex flex-wrap mb-3">
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
                        <div class="alert alert-warning py-2 mb-0 d-none" role="status" data-clinical-copilot-missing></div>
                    </div>
                    <div class="col-lg-5">
                        <h3 class="h6"><?php echo xlt('Available context'); ?></h3>
                        <dl class="row mb-0">
                            <dt class="col-sm-7"><?php echo xlt('Encounters'); ?></dt>
                            <dd class="col-sm-5" data-clinical-copilot-count="encounters"><?php echo text($copilotEncounterCount); ?></dd>

                            <dt class="col-sm-7"><?php echo xlt('Last encounter'); ?></dt>
                            <dd class="col-sm-5" data-clinical-copilot-last-encounter>
                                <?php echo !empty($copilotLastEncounterDate) ? text(oeFormatShortDate($copilotLastEncounterDate)) : xlt('None'); ?>
                            </dd>

                            <dt class="col-sm-7"><?php echo xlt('Active problems'); ?></dt>
                            <dd class="col-sm-5" data-clinical-copilot-count="problems"><?php echo $copilotProblemsCount === null ? text($copilotRestrictedText) : text($copilotProblemsCount); ?></dd>

                            <dt class="col-sm-7"><?php echo xlt('Active medications'); ?></dt>
                            <dd class="col-sm-5" data-clinical-copilot-count="medications"><?php echo $copilotMedicationsCount === null ? text($copilotRestrictedText) : text($copilotMedicationsCount); ?></dd>

                            <dt class="col-sm-7"><?php echo xlt('Active allergies'); ?></dt>
                            <dd class="col-sm-5" data-clinical-copilot-count="allergies"><?php echo $copilotAllergiesCount === null ? text($copilotRestrictedText) : text($copilotAllergiesCount); ?></dd>

                            <dt class="col-sm-7"><?php echo xlt('Lab results'); ?></dt>
                            <dd class="col-sm-5" data-clinical-copilot-count="labs"><?php echo $copilotLabsCount === null ? text($copilotRestrictedText) : text($copilotLabsCount); ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="row mt-3 d-none" data-clinical-copilot-ai-results>
                    <div class="col-lg-4">
                        <h3 class="h6"><?php echo xlt('Source-grounded summary'); ?></h3>
                        <p class="mb-0" data-clinical-copilot-ai-summary></p>
                    </div>
                    <div class="col-lg-4">
                        <h3 class="h6"><?php echo xlt('Risks to review'); ?></h3>
                        <ul class="mb-0 pl-3" data-clinical-copilot-ai-risks></ul>
                    </div>
                    <div class="col-lg-4">
                        <h3 class="h6"><?php echo xlt('Follow-up prompts'); ?></h3>
                        <ul class="mb-0 pl-3" data-clinical-copilot-ai-questions></ul>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
<script>
    (function () {
        const panel = document.querySelector('[data-clinical-copilot-endpoint]');
        if (!panel) {
            return;
        }

        const statusBadge = panel.querySelector('[data-clinical-copilot-status]');
        const summary = panel.querySelector('[data-clinical-copilot-summary]');
        const missing = panel.querySelector('[data-clinical-copilot-missing]');
        const refreshButton = panel.querySelector('[data-clinical-copilot-refresh]');
        const aiResults = panel.querySelector('[data-clinical-copilot-ai-results]');
        const aiSummary = panel.querySelector('[data-clinical-copilot-ai-summary]');
        const aiRisks = panel.querySelector('[data-clinical-copilot-ai-risks]');
        const aiQuestions = panel.querySelector('[data-clinical-copilot-ai-questions]');
        const endpoint = panel.dataset.clinicalCopilotEndpoint;
        const pid = panel.dataset.clinicalCopilotPid;
        const csrf = panel.dataset.clinicalCopilotCsrf;
        const restricted = <?php echo js_escape($copilotRestrictedText); ?>;

        function setStatus(text, className) {
            statusBadge.textContent = text;
            statusBadge.className = 'badge mr-2 ' + className;
        }

        function setCount(name, value, authorized) {
            const element = panel.querySelector('[data-clinical-copilot-count="' + name + '"]');
            if (!element) {
                return;
            }
            element.textContent = authorized === false ? restricted : String(value ?? 0);
        }

        function renderList(element, items) {
            element.innerHTML = '';
            items.forEach(function (item) {
                const li = document.createElement('li');
                const source = Array.isArray(item.source_refs) && item.source_refs.length > 0 ? ' [' + item.source_refs.join(', ') + ']' : '';
                li.textContent = (item.text || '') + source;
                element.appendChild(li);
            });
        }

        function renderContext(payload) {
            const context = payload.context || {};
            const ai = payload.ai_summary || {};
            const lastEncounter = context.encounters?.recent?.[0]?.date || null;
            const lastEncounterElement = panel.querySelector('[data-clinical-copilot-last-encounter]');

            if (payload.model?.summary_generated && ai.summary?.text) {
                summary.textContent = <?php echo js_escape(xl('AI summary generated for clinician review. Every displayed item includes source references.')); ?>;
                setStatus(<?php echo js_escape(xl('Summary ready')); ?>, 'badge-success');
                aiResults.classList.remove('d-none');
                aiSummary.textContent = ai.summary.text + ' [' + ai.summary.source_refs.join(', ') + ']';
                renderList(aiRisks, ai.risks || []);
                renderList(aiQuestions, ai.follow_up_questions || []);
            } else {
                summary.textContent = <?php echo js_escape(xl('Context loaded. AI summary generation is pending provider configuration.')); ?>;
                setStatus(<?php echo js_escape(xl('Context ready')); ?>, 'badge-success');
                aiResults.classList.add('d-none');
                aiSummary.textContent = '';
                aiRisks.innerHTML = '';
                aiQuestions.innerHTML = '';
            }

            setCount('encounters', context.encounters?.count, true);
            setCount('problems', context.problems?.count, context.problems?.authorized);
            setCount('medications', context.medications?.count, context.medications?.authorized);
            setCount('allergies', context.allergies?.count, context.allergies?.authorized);
            setCount('labs', context.labs?.count, context.labs?.authorized);

            if (lastEncounterElement) {
                lastEncounterElement.textContent = lastEncounter || <?php echo js_escape(xl('None')); ?>;
            }

            if (payload.missing_sources && payload.missing_sources.length > 0) {
                missing.classList.remove('d-none');
                missing.textContent = <?php echo js_escape(xl('Missing or limited sources')); ?> + ': ' + payload.missing_sources.join('; ');
            } else {
                missing.classList.add('d-none');
                missing.textContent = '';
            }
        }

        async function loadContext() {
            const body = new URLSearchParams();
            body.append('pid', pid);
            body.append('csrf_token_form', csrf);

            setStatus(<?php echo js_escape(xl('Loading')); ?>, 'badge-info');
            refreshButton.disabled = true;

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: body.toString()
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || <?php echo js_escape(xl('Clinical Co-Pilot context request failed.')); ?>);
                }

                renderContext(payload);
            } catch (error) {
                setStatus(<?php echo js_escape(xl('Unavailable')); ?>, 'badge-danger');
                summary.textContent = error.message || <?php echo js_escape(xl('Clinical Co-Pilot context is unavailable.')); ?>;
                missing.classList.add('d-none');
                missing.textContent = '';
                aiResults.classList.add('d-none');
            } finally {
                refreshButton.disabled = false;
            }
        }

        refreshButton.addEventListener('click', function () {
            loadContext();
        });

        loadContext();
    })();
</script>
