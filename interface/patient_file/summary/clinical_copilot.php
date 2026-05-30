<?php

/**
 * AgentForge Clinical Co-Pilot patient context endpoint.
 *
 * This endpoint returns a read-only, ACL-filtered context packet for the
 * patient dashboard. The OpenAI summarization layer will consume this contract
 * in a later slice; no model calls happen here.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");

$srcdir = \OpenEMR\Core\OEGlobalsBag::getInstance()->getSrcDir();
require_once($srcdir . "/patient.inc.php");
require_once($srcdir . "/options.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;

$session = SessionWrapperFactory::getInstance()->getActiveSession();

header("Content-Type: application/json");

function clinicalCopilotJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
}

function clinicalCopilotAudit(bool $success, string $comment, ?int $pid = null): void
{
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    EventAuditLogger::getInstance()->newEvent(
        "clinical-copilot",
        $session->get('authUser') ?? '',
        $session->get('authProvider') ?? '',
        $success ? 1 : 0,
        $comment,
        $pid
    );
}

function clinicalCopilotDate(?string $date): ?string
{
    if (empty($date)) {
        return null;
    }

    return substr($date, 0, 10);
}

function clinicalCopilotActiveIssueCount(int $pid, string $type): int
{
    $row = sqlQuery(
        "SELECT COUNT(*) AS count FROM lists WHERE pid = ? AND type = ? AND " . dateEmptySql('enddate'),
        [$pid, $type]
    );

    return (int) ($row['count'] ?? 0);
}

function clinicalCopilotActiveIssues(int $pid, string $type, int $limit = 8): array
{
    $res = sqlStatement(
        "SELECT id, title, begdate, diagnosis FROM lists " .
        "WHERE pid = ? AND type = ? AND " . dateEmptySql('enddate') .
        " ORDER BY begdate DESC, id DESC LIMIT " . escape_limit($limit),
        [$pid, $type]
    );

    $issues = [];
    while ($row = sqlFetchArray($res)) {
        $issues[] = [
            'id' => (int) $row['id'],
            'title' => $row['title'] ?? '',
            'begdate' => clinicalCopilotDate($row['begdate'] ?? null),
            'diagnosis' => $row['diagnosis'] ?? '',
            'source' => [
                'table' => 'lists',
                'id' => (int) $row['id'],
            ],
        ];
    }

    return $issues;
}

function clinicalCopilotRecentEncounters(int $pid, int $limit = 5): array
{
    $res = sqlStatement(
        "SELECT encounter, date, reason, provider_id FROM form_encounter " .
        "WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT " . escape_limit($limit),
        [$pid]
    );

    $encounters = [];
    while ($row = sqlFetchArray($res)) {
        $encounters[] = [
            'encounter' => (int) $row['encounter'],
            'date' => clinicalCopilotDate($row['date'] ?? null),
            'reason' => $row['reason'] ?? '',
            'provider_id' => isset($row['provider_id']) ? (int) $row['provider_id'] : null,
            'source' => [
                'table' => 'form_encounter',
                'id' => (int) $row['encounter'],
            ],
        ];
    }

    return $encounters;
}

function clinicalCopilotLabCount(int $pid): int
{
    $row = sqlQuery(
        "SELECT COUNT(*) AS count FROM procedure_result AS pr " .
        "JOIN procedure_report AS prep ON prep.procedure_report_id = pr.procedure_report_id " .
        "JOIN procedure_order AS po ON po.procedure_order_id = prep.procedure_order_id " .
        "WHERE po.patient_id = ?",
        [$pid]
    );

    return (int) ($row['count'] ?? 0);
}

function clinicalCopilotRecentLabs(int $pid, int $limit = 8): array
{
    $res = sqlStatement(
        "SELECT pr.procedure_result_id, pr.result, pr.units, pr.range, pr.date, " .
        "prep.date_collected, poc.procedure_name, poc.procedure_code FROM procedure_result AS pr " .
        "JOIN procedure_report AS prep ON prep.procedure_report_id = pr.procedure_report_id " .
        "JOIN procedure_order AS po ON po.procedure_order_id = prep.procedure_order_id " .
        "LEFT JOIN procedure_order_code AS poc ON poc.procedure_order_id = po.procedure_order_id " .
        "AND poc.procedure_order_seq = prep.procedure_order_seq " .
        "WHERE po.patient_id = ? ORDER BY prep.date_collected DESC, pr.procedure_result_id DESC LIMIT " . escape_limit($limit),
        [$pid]
    );

    $labs = [];
    while ($row = sqlFetchArray($res)) {
        $labs[] = [
            'id' => (int) $row['procedure_result_id'],
            'name' => $row['procedure_name'] ?? '',
            'code' => $row['procedure_code'] ?? '',
            'result' => $row['result'] ?? '',
            'units' => $row['units'] ?? '',
            'range' => $row['range'] ?? '',
            'date' => clinicalCopilotDate($row['date_collected'] ?? $row['date'] ?? null),
            'source' => [
                'table' => 'procedure_result',
                'id' => (int) $row['procedure_result_id'],
            ],
        ];
    }

    return $labs;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    clinicalCopilotJsonResponse(405, [
        'error' => 'method_not_allowed',
        'message' => xl('Clinical Co-Pilot endpoint requires POST.'),
    ]);
}

CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);

$pid = isset($_POST['pid']) ? (int) $_POST['pid'] : (int) $session->get('pid', 0);
if ($pid <= 0) {
    clinicalCopilotAudit(false, 'context request missing patient id');
    clinicalCopilotJsonResponse(400, [
        'error' => 'missing_patient',
        'message' => xl('No active patient is selected.'),
    ]);
}

$patient = getPatientData($pid, "pid, pubpid, fname, lname, DOB, DATE_FORMAT(DOB,'%Y-%m-%d') as DOB_YMD, squad");
if (empty($patient['pid'])) {
    clinicalCopilotAudit(false, 'context request patient not found', $pid);
    clinicalCopilotJsonResponse(404, [
        'error' => 'patient_not_found',
        'message' => xl('Patient was not found.'),
    ]);
}

$authorized = AclMain::aclCheckCore('patients', 'demo');
if ($authorized && !empty($patient['squad']) && !AclMain::aclCheckCore('squads', $patient['squad'])) {
    $authorized = false;
}

if (!$authorized) {
    clinicalCopilotAudit(false, 'context request denied by patient ACL', $pid);
    clinicalCopilotJsonResponse(403, [
        'error' => 'not_authorized',
        'message' => xl('You are not authorized to view this patient context.'),
    ]);
}

$canReadProblems = AclMain::aclCheckIssue('medical_problem');
$canReadMedications = AclMain::aclCheckIssue('medication');
$canReadAllergies = AclMain::aclCheckIssue('allergy');
$canReadLabs = AclMain::aclCheckCore('patients', 'lab');

$encounters = clinicalCopilotRecentEncounters($pid);
$context = [
    'patient' => [
        'pid' => (int) $patient['pid'],
        'pubpid' => $patient['pubpid'] ?? '',
        'name' => trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? '')),
        'dob' => clinicalCopilotDate($patient['DOB_YMD'] ?? $patient['DOB'] ?? null),
        'source' => [
            'table' => 'patient_data',
            'id' => (int) $patient['pid'],
        ],
    ],
    'encounters' => [
        'count' => count($encounters),
        'recent' => $encounters,
    ],
    'problems' => [
        'authorized' => $canReadProblems,
        'count' => $canReadProblems ? clinicalCopilotActiveIssueCount($pid, 'medical_problem') : null,
        'active' => $canReadProblems ? clinicalCopilotActiveIssues($pid, 'medical_problem') : [],
    ],
    'medications' => [
        'authorized' => $canReadMedications,
        'count' => $canReadMedications ? clinicalCopilotActiveIssueCount($pid, 'medication') : null,
        'active' => $canReadMedications ? clinicalCopilotActiveIssues($pid, 'medication') : [],
    ],
    'allergies' => [
        'authorized' => $canReadAllergies,
        'count' => $canReadAllergies ? clinicalCopilotActiveIssueCount($pid, 'allergy') : null,
        'active' => $canReadAllergies ? clinicalCopilotActiveIssues($pid, 'allergy') : [],
    ],
    'labs' => [
        'authorized' => $canReadLabs,
        'count' => $canReadLabs ? clinicalCopilotLabCount($pid) : null,
        'recent' => $canReadLabs ? clinicalCopilotRecentLabs($pid) : [],
    ],
];

$missingSources = [];
foreach (['problems', 'medications', 'allergies', 'labs'] as $section) {
    if (!$context[$section]['authorized']) {
        $missingSources[] = $section . ': restricted by ACL';
    } elseif (($context[$section]['count'] ?? 0) === 0) {
        $missingSources[] = $section . ': no active records found';
    }
}

clinicalCopilotAudit(true, 'context request completed', $pid);

clinicalCopilotJsonResponse(200, [
    'status' => 'ready_for_ai_provider',
    'feature' => 'agentforge_clinical_copilot',
    'model' => [
        'provider' => 'not_configured',
        'summary_generated' => false,
    ],
    'context' => $context,
    'missing_sources' => $missingSources,
    'response_contract' => [
        'summary' => null,
        'risks' => [],
        'follow_up_questions' => [],
        'source_references_required' => true,
        'unsupported_claim_policy' => 'omit_or_mark_insufficient_evidence',
    ],
]);
