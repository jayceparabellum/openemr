<?php

/**
 * AgentForge Clinical Co-Pilot patient context endpoint.
 *
 * This endpoint returns a read-only, ACL-filtered context packet for the
 * patient dashboard. If OPENAI_API_KEY is configured server-side, it also asks
 * OpenAI for a structured, source-grounded pre-visit brief.
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

function clinicalCopilotAuditComment(string $mode, string $provider, string $model, string $status, array $details = []): string
{
    $parts = [
        'mode=' . $mode,
        'provider=' . $provider,
        'model=' . $model,
        'status=' . $status,
    ];

    foreach ($details as $key => $value) {
        $parts[] = $key . '=' . $value;
    }

    return implode('; ', $parts);
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

function clinicalCopilotSourceKey(array $source): string
{
    return ($source['table'] ?? '') . ':' . (string) ($source['id'] ?? '');
}

function clinicalCopilotCollectSourceKeys(array $value): array
{
    $keys = [];

    if (isset($value['source']) && is_array($value['source'])) {
        $key = clinicalCopilotSourceKey($value['source']);
        if ($key !== ':') {
            $keys[$key] = true;
        }
    }

    foreach ($value as $child) {
        if (is_array($child)) {
            $keys += clinicalCopilotCollectSourceKeys($child);
        }
    }

    return $keys;
}

function clinicalCopilotBuildSourceLabels(array $context): array
{
    $labels = [];

    if (!empty($context['patient']['source']) && is_array($context['patient']['source'])) {
        $labels[clinicalCopilotSourceKey($context['patient']['source'])] = 'Patient demographics';
    }

    foreach (($context['encounters']['recent'] ?? []) as $encounter) {
        if (!empty($encounter['source']) && is_array($encounter['source'])) {
            $date = empty($encounter['date']) ? '' : ' ' . $encounter['date'];
            $labels[clinicalCopilotSourceKey($encounter['source'])] = 'Encounter' . $date;
        }
    }

    foreach (['problems' => 'Problem', 'medications' => 'Medication', 'allergies' => 'Allergy'] as $section => $labelPrefix) {
        foreach (($context[$section]['active'] ?? []) as $item) {
            if (!empty($item['source']) && is_array($item['source'])) {
                $title = trim((string) ($item['title'] ?? ''));
                $labels[clinicalCopilotSourceKey($item['source'])] = $labelPrefix . (empty($title) ? '' : ': ' . $title);
            }
        }
    }

    foreach (($context['labs']['recent'] ?? []) as $lab) {
        if (!empty($lab['source']) && is_array($lab['source'])) {
            $name = trim((string) ($lab['name'] ?? ''));
            if (empty($name)) {
                $name = trim((string) ($lab['code'] ?? ''));
            }
            $labels[clinicalCopilotSourceKey($lab['source'])] = 'Lab' . (empty($name) ? '' : ': ' . $name);
        }
    }

    return $labels;
}

function clinicalCopilotValidateItemSources(array $item, array $allowedSources): bool
{
    if (empty($item['source_refs']) || !is_array($item['source_refs'])) {
        return false;
    }

    foreach ($item['source_refs'] as $sourceRef) {
        if (!is_string($sourceRef) || empty($allowedSources[$sourceRef])) {
            return false;
        }
    }

    return true;
}

function clinicalCopilotValidateAiSummary(array $summary, array $allowedSources): array
{
    $validated = [
        'summary' => null,
        'risks' => [],
        'follow_up_questions' => [],
        'validation' => [
            'source_references_required' => true,
            'passed' => true,
            'dropped_items' => 0,
        ],
    ];

    if (isset($summary['summary']) && is_array($summary['summary']) && clinicalCopilotValidateItemSources($summary['summary'], $allowedSources)) {
        $validated['summary'] = [
            'text' => (string) ($summary['summary']['text'] ?? ''),
            'source_refs' => array_values($summary['summary']['source_refs']),
        ];
    } else {
        $validated['validation']['passed'] = false;
        $validated['validation']['dropped_items']++;
    }

    foreach (['risks', 'follow_up_questions'] as $listName) {
        if (empty($summary[$listName]) || !is_array($summary[$listName])) {
            continue;
        }

        foreach ($summary[$listName] as $item) {
            if (!is_array($item) || !clinicalCopilotValidateItemSources($item, $allowedSources)) {
                $validated['validation']['passed'] = false;
                $validated['validation']['dropped_items']++;
                continue;
            }

            $validated[$listName][] = [
                'text' => (string) ($item['text'] ?? ''),
                'source_refs' => array_values($item['source_refs']),
            ];
        }
    }

    return $validated;
}

function clinicalCopilotSchema(): array
{
    $sourceItem = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['text', 'source_refs'],
        'properties' => [
            'text' => [
                'type' => 'string',
                'description' => 'A concise clinical statement grounded only in the provided context.',
            ],
            'source_refs' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'string',
                    'description' => 'Source reference in table:id format from the provided context.',
                ],
            ],
        ],
    ];

    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['summary', 'risks', 'follow_up_questions'],
        'properties' => [
            'summary' => $sourceItem,
            'risks' => [
                'type' => 'array',
                'items' => $sourceItem,
            ],
            'follow_up_questions' => [
                'type' => 'array',
                'items' => $sourceItem,
            ],
        ],
    ];
}

function clinicalCopilotExtractText(array $response): string
{
    if (!empty($response['output_text']) && is_string($response['output_text'])) {
        return $response['output_text'];
    }

    $texts = [];
    foreach (($response['output'] ?? []) as $output) {
        foreach (($output['content'] ?? []) as $content) {
            if (isset($content['text']) && is_string($content['text'])) {
                $texts[] = $content['text'];
            }
        }
    }

    return trim(implode("\n", $texts));
}

function clinicalCopilotGenerateAiSummary(array $context, array $missingSources, array $allowedSources): array
{
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    $provider = 'openai';
    $model = getenv('AGENTFORGE_OPENAI_MODEL') ?: (getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');

    if (empty($apiKey)) {
        return [
            'model' => [
                'provider' => $provider,
                'name' => $model,
                'summary_generated' => false,
                'status' => 'not_configured',
            ],
            'ai_summary' => [
                'summary' => null,
                'risks' => [],
                'follow_up_questions' => [],
                'validation' => [
                    'source_references_required' => true,
                    'passed' => null,
                    'dropped_items' => 0,
                ],
            ],
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'model' => [
                'provider' => $provider,
                'name' => $model,
                'summary_generated' => false,
                'status' => 'curl_unavailable',
            ],
            'ai_summary' => [
                'summary' => null,
                'risks' => [],
                'follow_up_questions' => [],
                'validation' => [
                    'source_references_required' => true,
                    'passed' => false,
                    'dropped_items' => 0,
                ],
            ],
        ];
    }

    $sourceKeys = array_keys($allowedSources);
    sort($sourceKeys);
    $prompt = [
        'task' => 'Create a concise primary care pre-visit brief as JSON only.',
        'rules' => [
            'Use only the provided context.',
            'Every summary, risk, and follow-up question must include one or more source_refs from allowed_source_refs.',
            'Do not infer diagnoses, lab values, medications, allergies, or plans that are not present.',
            'If evidence is missing, make the relevant follow-up question rather than inventing detail.',
        ],
        'allowed_source_refs' => $sourceKeys,
        'missing_sources' => $missingSources,
        'context' => $context,
    ];

    $requestBody = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => 'You are a careful clinical documentation assistant. Return source-grounded JSON for clinician review only. Do not provide diagnosis or treatment recommendations beyond the supplied record.',
            ],
            [
                'role' => 'user',
                'content' => json_encode($prompt, JSON_UNESCAPED_SLASHES),
            ],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'clinical_copilot_summary',
                'strict' => true,
                'schema' => clinicalCopilotSchema(),
            ],
        ],
        'max_output_tokens' => 900,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_SLASHES),
    ]);

    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rawResponse === false || $httpCode < 200 || $httpCode >= 300) {
        return [
            'model' => [
                'provider' => $provider,
                'name' => $model,
                'summary_generated' => false,
                'status' => 'provider_error',
                'http_status' => $httpCode,
            ],
            'ai_summary' => [
                'summary' => null,
                'risks' => [],
                'follow_up_questions' => [],
                'validation' => [
                    'source_references_required' => true,
                    'passed' => false,
                    'dropped_items' => 0,
                ],
                'error' => empty($curlError) ? 'OpenAI request failed.' : $curlError,
            ],
        ];
    }

    $decodedResponse = json_decode($rawResponse, true);
    $outputText = is_array($decodedResponse) ? clinicalCopilotExtractText($decodedResponse) : '';
    $summary = json_decode($outputText, true);

    if (!is_array($summary)) {
        return [
            'model' => [
                'provider' => $provider,
                'name' => $model,
                'summary_generated' => false,
                'status' => 'invalid_provider_json',
            ],
            'ai_summary' => [
                'summary' => null,
                'risks' => [],
                'follow_up_questions' => [],
                'validation' => [
                    'source_references_required' => true,
                    'passed' => false,
                    'dropped_items' => 0,
                ],
            ],
        ];
    }

    $validated = clinicalCopilotValidateAiSummary($summary, $allowedSources);

    return [
        'model' => [
            'provider' => $provider,
            'name' => $model,
            'summary_generated' => $validated['summary'] !== null,
            'status' => $validated['summary'] !== null ? 'generated' : 'validation_failed',
        ],
        'ai_summary' => $validated,
    ];
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

$allowedSources = clinicalCopilotCollectSourceKeys($context);
$sourceLabels = clinicalCopilotBuildSourceLabels($context);
$aiResult = clinicalCopilotGenerateAiSummary($context, $missingSources, $allowedSources);
$model = $aiResult['model'];
$mode = $model['summary_generated'] ? 'ai-summary' : 'context-only';

clinicalCopilotAudit(
    true,
    clinicalCopilotAuditComment(
        $mode,
        $model['provider'] ?? 'unknown',
        $model['name'] ?? 'unknown',
        $model['status'] ?? 'unknown',
        [
            'source_count' => count($allowedSources),
            'missing_count' => count($missingSources),
            'dropped_items' => $aiResult['ai_summary']['validation']['dropped_items'] ?? 0,
        ]
    ),
    $pid
);

clinicalCopilotJsonResponse(200, [
    'status' => $model['summary_generated'] ? 'summary_generated' : 'ready_for_ai_provider',
    'feature' => 'agentforge_clinical_copilot',
    'model' => $model,
    'context' => $context,
    'missing_sources' => $missingSources,
    'source_index' => array_keys($allowedSources),
    'source_labels' => $sourceLabels,
    'ai_summary' => $aiResult['ai_summary'],
    'response_contract' => [
        'summary' => null,
        'risks' => [],
        'follow_up_questions' => [],
        'source_references_required' => true,
        'unsupported_claim_policy' => 'omit_or_mark_insufficient_evidence',
    ],
]);
