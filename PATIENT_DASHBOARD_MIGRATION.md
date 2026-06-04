# Patient Dashboard Migration Defense

## Requirement

Week 2 adds a surprise challenge: reimplement the OpenEMR patient dashboard in a modern framework, consuming OpenEMR REST/FHIR APIs as the data layer. The work should port the presentation layer, not redesign the backend.

Required dashboard scope:

- OAuth2/OpenID Connect login.
- Patient header with name, DOB, sex, MRN, and active status.
- Clinical cards:
  - Allergies
  - Problem List
  - Medications
  - Prescriptions
  - Care Team
- One additional API-backed section.

Recommended additional section: lab results, because Week 2 already centers on lab PDF ingestion and extracted Observation data.

## Framework Recommendation

Use React with Vite for the Week 2 dashboard port.

Why React/Vite:

- Fastest path to a focused dashboard surface inside the sprint.
- Mature OAuth/OIDC client ecosystem.
- Strong component model for patient header, clinical cards, loading states, and citation/source panels.
- Easy to embed or proxy beside the existing OpenEMR PHP app during a transition.
- Lower operational complexity than a full Next.js server layer when OpenEMR remains the backend.

## What We Gain By Moving Presentation Away From PHP

- Clear client-side component boundaries.
- Better state handling for repeated clinical cards.
- Cleaner loading, empty, and error states for API-backed data.
- Easier future UI testing with Playwright.
- More natural integration with the Co-Pilot panel, source chips, and document preview states.

## Tradeoffs

- Adds a frontend build toolchain to an existing PHP-heavy codebase.
- Requires careful OAuth token handling and same-origin/proxy planning.
- May duplicate some patient-dashboard presentation logic during migration.
- Full feature parity is hard; Week 2 should target the required cards first.

## Migration Strategy

1. Keep OpenEMR PHP backend and APIs unchanged.
2. Create a modern dashboard app in a clearly isolated directory.
3. Authenticate with OpenEMR OAuth/OIDC.
4. Fetch FHIR resources for the required patient sections.
5. Match the existing dashboard's information hierarchy before making visual improvements.
6. Add the Week 2 Co-Pilot/document evidence panel once the core cards load.

## Proof Criteria

- User can authenticate.
- User can view a patient header.
- Required clinical cards load live API data.
- Missing or unauthorized data renders explicit empty/error states.
- The dashboard can be verified with a screenshot-based flow before submission.
