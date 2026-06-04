param(
    [string]$SchemaDir = "agentforge/week2/schemas",
    [string]$FixtureDir = "agentforge/week2/fixtures",
    [string]$OutputPath = "eval-results/agentforge-week2-schema-latest.json"
)

$ErrorActionPreference = "Stop"

function Read-JsonFile {
    param([string]$Path)

    if (!(Test-Path $Path)) {
        throw "File not found: $Path"
    }

    return Get-Content -Raw -Path $Path | ConvertFrom-Json
}

function Get-PropertyNames {
    param($Value)

    return @($Value.PSObject.Properties | ForEach-Object { $_.Name })
}

function Add-ValidationError {
    param(
        [System.Collections.Generic.List[string]]$Errors,
        [string]$Path,
        [string]$Message
    )

    $Errors.Add("${Path}: ${Message}")
}

function Test-RequiredProperties {
    param(
        $Object,
        [string[]]$Required,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    $names = Get-PropertyNames $Object
    foreach ($requiredName in $Required) {
        if ($names -notcontains $requiredName) {
            Add-ValidationError $Errors $Path "missing required property '$requiredName'"
        }
    }
}

function Test-NoAdditionalProperties {
    param(
        $Object,
        [string[]]$Allowed,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    foreach ($name in (Get-PropertyNames $Object)) {
        if ($Allowed -notcontains $name) {
            Add-ValidationError $Errors $Path "unexpected property '$name'"
        }
    }
}

function Test-NonEmptyString {
    param(
        $Value,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    if ($null -eq $Value -or $Value -isnot [string] -or [string]::IsNullOrWhiteSpace($Value)) {
        Add-ValidationError $Errors $Path "must be a non-empty string"
    }
}

function Test-DateString {
    param(
        $Value,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    Test-NonEmptyString $Value $Path $Errors
    if ($Value -is [string] -and $Value -notmatch '^\d{4}-\d{2}-\d{2}$') {
        Add-ValidationError $Errors $Path "must use YYYY-MM-DD format"
    }
}

function Test-DateTimeString {
    param(
        $Value,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    Test-NonEmptyString $Value $Path $Errors
    if ($Value -is [string]) {
        try {
            [datetimeoffset]::Parse($Value) | Out-Null
        } catch {
            Add-ValidationError $Errors $Path "must be an ISO-8601 date-time"
        }
    }
}

function Test-NumberRange {
    param(
        $Value,
        [double]$Minimum,
        [double]$Maximum,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    if ($Value -isnot [double] -and $Value -isnot [int] -and $Value -isnot [decimal]) {
        Add-ValidationError $Errors $Path "must be numeric"
        return
    }

    if ([double]$Value -lt $Minimum -or [double]$Value -gt $Maximum) {
        Add-ValidationError $Errors $Path "must be between $Minimum and $Maximum"
    }
}

function Test-PositiveInteger {
    param(
        $Value,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    if ($Value -isnot [int] -or $Value -lt 1) {
        Add-ValidationError $Errors $Path "must be an integer greater than zero"
    }
}

function Test-ArrayValue {
    param(
        $Value,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    if ($null -eq $Value -or $Value -isnot [System.Array]) {
        Add-ValidationError $Errors $Path "must be an array"
    }
}

function Test-Citation {
    param(
        $Citation,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    $allowed = @("source_type", "source_id", "page_or_section", "field_or_chunk_id", "quote_or_value", "bounding_box")
    $required = @("source_type", "source_id", "page_or_section", "field_or_chunk_id", "quote_or_value")
    Test-RequiredProperties $Citation $required $Path $Errors
    Test-NoAdditionalProperties $Citation $allowed $Path $Errors

    if ($Citation.source_type -notin @("openemr_document", "openemr_record", "guideline")) {
        Add-ValidationError $Errors "$Path.source_type" "must be openemr_document, openemr_record, or guideline"
    }

    Test-NonEmptyString $Citation.source_id "$Path.source_id" $Errors
    Test-NonEmptyString $Citation.page_or_section "$Path.page_or_section" $Errors
    Test-NonEmptyString $Citation.field_or_chunk_id "$Path.field_or_chunk_id" $Errors
    Test-NonEmptyString $Citation.quote_or_value "$Path.quote_or_value" $Errors

    if ($null -ne $Citation.bounding_box) {
        $boxAllowed = @("page", "x", "y", "width", "height")
        Test-RequiredProperties $Citation.bounding_box $boxAllowed "$Path.bounding_box" $Errors
        Test-NoAdditionalProperties $Citation.bounding_box $boxAllowed "$Path.bounding_box" $Errors
        Test-PositiveInteger $Citation.bounding_box.page "$Path.bounding_box.page" $Errors
        foreach ($coord in @("x", "y")) {
            if ($Citation.bounding_box.$coord -lt 0) {
                Add-ValidationError $Errors "$Path.bounding_box.$coord" "must be zero or greater"
            }
        }
        foreach ($size in @("width", "height")) {
            if ($Citation.bounding_box.$size -le 0) {
                Add-ValidationError $Errors "$Path.bounding_box.$size" "must be greater than zero"
            }
        }
    }
}

function Test-SourceDocument {
    param(
        $SourceDocument,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    $allowed = @("source_type", "source_id", "filename", "page_count")
    Test-RequiredProperties $SourceDocument $allowed $Path $Errors
    Test-NoAdditionalProperties $SourceDocument $allowed $Path $Errors

    if ($SourceDocument.source_type -ne "openemr_document") {
        Add-ValidationError $Errors "$Path.source_type" "must be openemr_document"
    }
    Test-NonEmptyString $SourceDocument.source_id "$Path.source_id" $Errors
    Test-NonEmptyString $SourceDocument.filename "$Path.filename" $Errors
    Test-PositiveInteger $SourceDocument.page_count "$Path.page_count" $Errors
}

function Test-ExtractionMetadata {
    param(
        $Extraction,
        [string]$Path,
        [System.Collections.Generic.List[string]]$Errors
    )

    $allowed = @("model", "extracted_at", "confidence")
    Test-RequiredProperties $Extraction $allowed $Path $Errors
    Test-NoAdditionalProperties $Extraction $allowed $Path $Errors
    Test-NonEmptyString $Extraction.model "$Path.model" $Errors
    Test-DateTimeString $Extraction.extracted_at "$Path.extracted_at" $Errors
    Test-NumberRange $Extraction.confidence 0 1 "$Path.confidence" $Errors
}

function Test-LabPdfExtraction {
    param($Payload)

    $errors = [System.Collections.Generic.List[string]]::new()
    $allowed = @("doc_type", "schema_version", "patient_id", "source_document", "extraction", "lab_results", "missing_fields", "warnings")
    Test-RequiredProperties $Payload @("doc_type", "schema_version", "patient_id", "source_document", "extraction", "lab_results") "$" $errors
    Test-NoAdditionalProperties $Payload $allowed "$" $errors

    if ($Payload.doc_type -ne "lab_pdf") {
        Add-ValidationError $errors "$.doc_type" "must be lab_pdf"
    }
    if ($Payload.schema_version -ne "week2.lab_pdf.v1") {
        Add-ValidationError $errors "$.schema_version" "must be week2.lab_pdf.v1"
    }
    Test-NonEmptyString $Payload.patient_id "$.patient_id" $errors
    Test-SourceDocument $Payload.source_document "$.source_document" $errors
    Test-ExtractionMetadata $Payload.extraction "$.extraction" $errors
    Test-ArrayValue $Payload.lab_results "$.lab_results" $errors

    if ($Payload.lab_results -is [System.Array]) {
        if ($Payload.lab_results.Count -lt 1) {
            Add-ValidationError $errors "$.lab_results" "must include at least one result"
        }

        for ($i = 0; $i -lt $Payload.lab_results.Count; $i++) {
            $item = $Payload.lab_results[$i]
            $path = "`$.lab_results[$i]"
            $itemAllowed = @("test_name", "value", "unit", "reference_range", "collection_date", "abnormal_flag", "source_citation")
            Test-RequiredProperties $item $itemAllowed $path $errors
            Test-NoAdditionalProperties $item $itemAllowed $path $errors
            Test-NonEmptyString $item.test_name "$path.test_name" $errors
            Test-NonEmptyString $item.value "$path.value" $errors
            Test-NonEmptyString $item.unit "$path.unit" $errors
            Test-NonEmptyString $item.reference_range "$path.reference_range" $errors
            Test-DateString $item.collection_date "$path.collection_date" $errors
            if ($item.abnormal_flag -notin @("low", "normal", "high", "critical", "unknown")) {
                Add-ValidationError $errors "$path.abnormal_flag" "must be low, normal, high, critical, or unknown"
            }
            Test-Citation $item.source_citation "$path.source_citation" $errors
        }
    }

    return $errors
}

function Test-IntakeFormExtraction {
    param($Payload)

    $errors = [System.Collections.Generic.List[string]]::new()
    $allowed = @("doc_type", "schema_version", "patient_id", "source_document", "extraction", "demographics", "chief_concern", "current_medications", "allergies", "family_history", "missing_fields", "warnings")
    Test-RequiredProperties $Payload @("doc_type", "schema_version", "patient_id", "source_document", "extraction", "demographics", "chief_concern", "current_medications", "allergies", "family_history") "$" $errors
    Test-NoAdditionalProperties $Payload $allowed "$" $errors

    if ($Payload.doc_type -ne "intake_form") {
        Add-ValidationError $errors "$.doc_type" "must be intake_form"
    }
    if ($Payload.schema_version -ne "week2.intake_form.v1") {
        Add-ValidationError $errors "$.schema_version" "must be week2.intake_form.v1"
    }
    Test-NonEmptyString $Payload.patient_id "$.patient_id" $errors
    Test-SourceDocument $Payload.source_document "$.source_document" $errors
    Test-ExtractionMetadata $Payload.extraction "$.extraction" $errors

    $demoAllowed = @("full_name", "date_of_birth", "sex", "mrn", "phone", "source_citation")
    Test-RequiredProperties $Payload.demographics @("full_name", "date_of_birth", "sex", "source_citation") "$.demographics" $errors
    Test-NoAdditionalProperties $Payload.demographics $demoAllowed "$.demographics" $errors
    Test-NonEmptyString $Payload.demographics.full_name "$.demographics.full_name" $errors
    Test-DateString $Payload.demographics.date_of_birth "$.demographics.date_of_birth" $errors
    Test-NonEmptyString $Payload.demographics.sex "$.demographics.sex" $errors
    Test-Citation $Payload.demographics.source_citation "$.demographics.source_citation" $errors

    Test-RequiredProperties $Payload.chief_concern @("text", "source_citation") "$.chief_concern" $errors
    Test-NoAdditionalProperties $Payload.chief_concern @("text", "source_citation") "$.chief_concern" $errors
    Test-NonEmptyString $Payload.chief_concern.text "$.chief_concern.text" $errors
    Test-Citation $Payload.chief_concern.source_citation "$.chief_concern.source_citation" $errors

    Test-ArrayValue $Payload.current_medications "$.current_medications" $errors
    Test-ArrayValue $Payload.allergies "$.allergies" $errors
    Test-ArrayValue $Payload.family_history "$.family_history" $errors

    if ($Payload.current_medications -is [System.Array]) {
        for ($i = 0; $i -lt $Payload.current_medications.Count; $i++) {
            $item = $Payload.current_medications[$i]
            $path = "`$.current_medications[$i]"
            $allowedMedication = @("name", "dose", "frequency", "source_citation")
            Test-RequiredProperties $item $allowedMedication $path $errors
            Test-NoAdditionalProperties $item $allowedMedication $path $errors
            Test-NonEmptyString $item.name "$path.name" $errors
            Test-NonEmptyString $item.dose "$path.dose" $errors
            Test-NonEmptyString $item.frequency "$path.frequency" $errors
            Test-Citation $item.source_citation "$path.source_citation" $errors
        }
    }

    if ($Payload.allergies -is [System.Array]) {
        for ($i = 0; $i -lt $Payload.allergies.Count; $i++) {
            $item = $Payload.allergies[$i]
            $path = "`$.allergies[$i]"
            $allowedAllergy = @("substance", "reaction", "source_citation")
            Test-RequiredProperties $item $allowedAllergy $path $errors
            Test-NoAdditionalProperties $item $allowedAllergy $path $errors
            Test-NonEmptyString $item.substance "$path.substance" $errors
            Test-NonEmptyString $item.reaction "$path.reaction" $errors
            Test-Citation $item.source_citation "$path.source_citation" $errors
        }
    }

    if ($Payload.family_history -is [System.Array]) {
        for ($i = 0; $i -lt $Payload.family_history.Count; $i++) {
            $item = $Payload.family_history[$i]
            $path = "`$.family_history[$i]"
            $allowedFamilyHistory = @("condition", "relationship", "source_citation")
            Test-RequiredProperties $item $allowedFamilyHistory $path $errors
            Test-NoAdditionalProperties $item $allowedFamilyHistory $path $errors
            Test-NonEmptyString $item.condition "$path.condition" $errors
            Test-NonEmptyString $item.relationship "$path.relationship" $errors
            Test-Citation $item.source_citation "$path.source_citation" $errors
        }
    }

    return $errors
}

function Test-SchemaFile {
    param(
        [string]$Path,
        [string[]]$ExpectedRequired
    )

    $schema = Read-JsonFile $Path
    $errors = [System.Collections.Generic.List[string]]::new()
    Test-NonEmptyString $schema.'$schema' "$Path.`$schema" $errors
    if ($schema.type -ne "object") {
        Add-ValidationError $errors $Path "top-level type must be object"
    }
    if ($schema.additionalProperties -ne $false) {
        Add-ValidationError $errors $Path "top-level additionalProperties must be false"
    }
    foreach ($name in $ExpectedRequired) {
        if (@($schema.required) -notcontains $name) {
            Add-ValidationError $errors $Path "schema required list must include '$name'"
        }
    }
    return $errors
}

function Add-Result {
    param(
        [System.Collections.Generic.List[object]]$Results,
        [string]$Id,
        [string]$Scenario,
        [bool]$Passed,
        [string]$Notes
    )

    $Results.Add([pscustomobject]@{
        id = $Id
        scenario = $Scenario
        passed = $Passed
        notes = $Notes
    })
}

$results = [System.Collections.Generic.List[object]]::new()

$labSchemaErrors = Test-SchemaFile `
    -Path (Join-Path $SchemaDir "lab_pdf.schema.json") `
    -ExpectedRequired @("doc_type", "schema_version", "patient_id", "source_document", "extraction", "lab_results")
Add-Result $results "W2-SCHEMA-001" "Lab PDF schema contract" ($labSchemaErrors.Count -eq 0) ($labSchemaErrors -join "; ")

$intakeSchemaErrors = Test-SchemaFile `
    -Path (Join-Path $SchemaDir "intake_form.schema.json") `
    -ExpectedRequired @("doc_type", "schema_version", "patient_id", "source_document", "extraction", "demographics", "chief_concern", "current_medications", "allergies", "family_history")
Add-Result $results "W2-SCHEMA-002" "Intake form schema contract" ($intakeSchemaErrors.Count -eq 0) ($intakeSchemaErrors -join "; ")

$validLab = Read-JsonFile (Join-Path $FixtureDir "valid-lab-pdf.json")
$validLabErrors = Test-LabPdfExtraction $validLab
Add-Result $results "W2-SCHEMA-003" "Valid lab PDF fixture" ($validLabErrors.Count -eq 0) ($validLabErrors -join "; ")

$invalidLab = Read-JsonFile (Join-Path $FixtureDir "invalid-lab-pdf-missing-citation.json")
$invalidLabErrors = Test-LabPdfExtraction $invalidLab
Add-Result $results "W2-SCHEMA-004" "Invalid lab PDF fixture fails missing citation" ($invalidLabErrors.Count -gt 0) ($invalidLabErrors -join "; ")

$validIntake = Read-JsonFile (Join-Path $FixtureDir "valid-intake-form.json")
$validIntakeErrors = Test-IntakeFormExtraction $validIntake
Add-Result $results "W2-SCHEMA-005" "Valid intake form fixture" ($validIntakeErrors.Count -eq 0) ($validIntakeErrors -join "; ")

$invalidIntake = Read-JsonFile (Join-Path $FixtureDir "invalid-intake-form-extra-field.json")
$invalidIntakeErrors = Test-IntakeFormExtraction $invalidIntake
Add-Result $results "W2-SCHEMA-006" "Invalid intake fixture fails extra field" ($invalidIntakeErrors.Count -gt 0) ($invalidIntakeErrors -join "; ")

$summary = [pscustomobject]@{
    started_at = (Get-Date).ToString("o")
    schema_dir = $SchemaDir
    fixture_dir = $FixtureDir
    passed = ($results | Where-Object { -not $_.passed }).Count -eq 0
    results = $results
}

$outputFullPath = Join-Path (Get-Location) $OutputPath
$outputDir = Split-Path $outputFullPath -Parent
if (!(Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$summary | ConvertTo-Json -Depth 10 | Set-Content -Path $outputFullPath -Encoding UTF8

$results | Format-Table id, passed, scenario, notes -AutoSize
if (-not $summary.passed) {
    throw "One or more AgentForge Week 2 schema evals failed. See $OutputPath."
}

Write-Host "AgentForge Week 2 schema evals passed. Results: $OutputPath"
