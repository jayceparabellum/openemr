# AgentForge Week 1 Users

## Target User

Primary care physician with a full clinic day who needs to prepare for the next patient in roughly 90 seconds between rooms.

## User Context

The physician is moving quickly between appointments. They need to recall who the patient is, why they are here, what changed since the last visit, what medications and labs matter today, and whether anything requires attention before entering the room.

The physician is already inside OpenEMR or about to open the patient chart. The Co-Pilot should reduce chart-scanning time without asking the physician to trust unsupported claims.

## Primary Workflow

1. Physician opens a patient chart or schedule item.
2. Co-Pilot detects the current patient context.
3. Co-Pilot generates a pre-visit brief from authorized OpenEMR data.
4. Physician scans the brief, checks source chips for important claims, and asks follow-up questions.
5. Co-Pilot refuses or qualifies answers when data is missing, conflicting, unsupported, or outside its allowed scope.

## Use Cases

### Use Case 1: Pre-Visit Brief

**Moment:** The physician has 90 seconds before entering the room.

**Need:** Understand what changed since the last visit and what matters today.

**Agent behavior:** Summarize recent encounters, active problems, medication changes, recent labs, upcoming visit reason, and open concerns. Each factual claim must include a source.

**Why an agent:** The physician may ask follow-up questions that depend on the first answer, such as "What changed since last visit?" or "Only show abnormal labs." A static dashboard would either show too much or require repeated manual filtering.

### Use Case 2: Medication Change Check

**Moment:** The physician is reconciling medications before the patient enters.

**Need:** Identify medication additions, removals, dosage changes, and possible source conflicts.

**Agent behavior:** Retrieve medication history, compare recent changes, cite the records used, and flag uncertainty when data is incomplete.

**Why an agent:** The physician's question is contextual and often iterative. They may ask "since last visit," "for hypertension meds only," or "show source."

### Use Case 3: Recent Labs And Risk Flags

**Moment:** The physician wants to know whether recent labs require action.

**Need:** Surface abnormal or clinically relevant labs without claiming unsupported diagnosis or treatment advice.

**Agent behavior:** Show recent lab results, abnormal flags if available, dates, trend notes when supported, and source links.

**Why an agent:** The physician can ask focused follow-ups, but the system must distinguish chart summarization from unsupported clinical decision-making.

## Non-Goals

- The Co-Pilot is not a generic medical advice chatbot.
- The Co-Pilot is not allowed to answer questions about patients the user is not authorized to access.
- The Co-Pilot should not make unsupported diagnoses or treatment recommendations.
- The first version does not need to solve every specialty workflow.

## Success Criteria

- A primary care physician can understand the next patient's recent context faster than manual chart scanning.
- Every patient-specific factual claim is traceable to OpenEMR data.
- Missing or conflicting data is visible rather than hidden.
- The workflow is fast enough to be useful between rooms.

