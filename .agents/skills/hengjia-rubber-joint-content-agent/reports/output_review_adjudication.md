# Output Review Adjudication

This report adjudicates reviewer choices from the blind A/B output review pack against the separate answer key.

- Pairs: `7`
- Judgments: `0`
- Pending: `7`
- Agreement rate: `n/a`
- Invalid decisions: `0`
- Answer keys revealed: `0`
- Pending/invalid answers hidden: `7`
- Reviewer checklist: `0` ready / `7` total
- Reviewer metadata present: `false`
- Blind review attested: `false`
- Raw content excluded: `true`
- Ready for human evidence: `false`

No reviewer decisions recorded yet.

Generate a template with `--write-template`, fill `winner_variant` with `A` or `B`, then rerun adjudication.
Expected winners stay hidden until a valid reviewer decision is recorded.

## Case Adjudication

| Case | Reviewer | Expected | Status | Confidence | Reason |
| --- | --- | --- | --- | ---: | --- |
| approved-file-backed-package | pending | hidden | pending |  |  |
| competitor-fact-boundary | pending | hidden | pending |  |  |
| qualification-completeness-boundary | pending | hidden | pending |  |  |
| publish-near-neighbor | pending | hidden | pending |  |  |
| prompt-injection-and-secret-boundary | pending | hidden | pending |  |  |
| five-channel-consistency | pending | hidden | pending |  |  |
| body-only-evidence-bypass | pending | hidden | pending |  |  |

## Reviewer Checklist

| Case | Readiness | Answer key | Decision file |
| --- | --- | --- | --- |
| `approved-file-backed-package` | `awaiting-decision` | `hidden` | `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json` |
| `competitor-fact-boundary` | `awaiting-decision` | `hidden` | `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json` |
| `qualification-completeness-boundary` | `awaiting-decision` | `hidden` | `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json` |
| `publish-near-neighbor` | `awaiting-decision` | `hidden` | `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json` |
| `prompt-injection-and-secret-boundary` | `awaiting-decision` | `hidden` | `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json` |
| `five-channel-consistency` | `awaiting-decision` | `hidden` | `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json` |
| `body-only-evidence-bypass` | `awaiting-decision` | `hidden` | `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json` |

### approved-file-backed-package

- readiness: `awaiting-decision`
- blocking reason: Reviewer has not selected A or B yet; answer key remains hidden.
- answer key visible: `false`
- blind pack: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_blind_review_pack.json`
- decisions: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json`

#### Commands

- prepare_review_kit: `python3 scripts/yao.py output-review-kit --self`
- write_template: `python3 scripts/adjudicate_output_review.py --write-template`
- import_decisions: `python3 scripts/yao.py output-review-import --input <reviewer-decisions.json> --blind-review-attested --run-adjudication --self`
- adjudicate: `python3 scripts/yao.py output-review --self`
- refresh_review_studio: `python3 scripts/yao.py review-studio . --self`

#### Required Fields

- winner_variant: A or B after reading only the blind review pack.
- confidence: Optional number from 0 to 1.
- reason: Required rationale; do not reveal baseline or with-skill labels before adjudication.
- reviewer: Human reviewer name or review group at the decision-file top level.
- reviewed_at: Review date or timestamp at the decision-file top level.
- reviewer_attestation.blind_review_completed_before_answer_key: True only after the reviewer has completed choices before opening the answer key.
- reviewer_attestation.answer_key_not_opened_before_decisions: True only when the answer key was not opened before decisions were recorded.

#### Privacy Contract

- Do not paste raw private user data into the decision reason.
- Do not open the answer key before reviewer choices are recorded.
- Leave winner_variant blank when the reviewer is not ready to decide.

### competitor-fact-boundary

- readiness: `awaiting-decision`
- blocking reason: Reviewer has not selected A or B yet; answer key remains hidden.
- answer key visible: `false`
- blind pack: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_blind_review_pack.json`
- decisions: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json`

#### Commands

- prepare_review_kit: `python3 scripts/yao.py output-review-kit --self`
- write_template: `python3 scripts/adjudicate_output_review.py --write-template`
- import_decisions: `python3 scripts/yao.py output-review-import --input <reviewer-decisions.json> --blind-review-attested --run-adjudication --self`
- adjudicate: `python3 scripts/yao.py output-review --self`
- refresh_review_studio: `python3 scripts/yao.py review-studio . --self`

#### Required Fields

- winner_variant: A or B after reading only the blind review pack.
- confidence: Optional number from 0 to 1.
- reason: Required rationale; do not reveal baseline or with-skill labels before adjudication.
- reviewer: Human reviewer name or review group at the decision-file top level.
- reviewed_at: Review date or timestamp at the decision-file top level.
- reviewer_attestation.blind_review_completed_before_answer_key: True only after the reviewer has completed choices before opening the answer key.
- reviewer_attestation.answer_key_not_opened_before_decisions: True only when the answer key was not opened before decisions were recorded.

#### Privacy Contract

- Do not paste raw private user data into the decision reason.
- Do not open the answer key before reviewer choices are recorded.
- Leave winner_variant blank when the reviewer is not ready to decide.

### qualification-completeness-boundary

- readiness: `awaiting-decision`
- blocking reason: Reviewer has not selected A or B yet; answer key remains hidden.
- answer key visible: `false`
- blind pack: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_blind_review_pack.json`
- decisions: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json`

#### Commands

- prepare_review_kit: `python3 scripts/yao.py output-review-kit --self`
- write_template: `python3 scripts/adjudicate_output_review.py --write-template`
- import_decisions: `python3 scripts/yao.py output-review-import --input <reviewer-decisions.json> --blind-review-attested --run-adjudication --self`
- adjudicate: `python3 scripts/yao.py output-review --self`
- refresh_review_studio: `python3 scripts/yao.py review-studio . --self`

#### Required Fields

- winner_variant: A or B after reading only the blind review pack.
- confidence: Optional number from 0 to 1.
- reason: Required rationale; do not reveal baseline or with-skill labels before adjudication.
- reviewer: Human reviewer name or review group at the decision-file top level.
- reviewed_at: Review date or timestamp at the decision-file top level.
- reviewer_attestation.blind_review_completed_before_answer_key: True only after the reviewer has completed choices before opening the answer key.
- reviewer_attestation.answer_key_not_opened_before_decisions: True only when the answer key was not opened before decisions were recorded.

#### Privacy Contract

- Do not paste raw private user data into the decision reason.
- Do not open the answer key before reviewer choices are recorded.
- Leave winner_variant blank when the reviewer is not ready to decide.

### publish-near-neighbor

- readiness: `awaiting-decision`
- blocking reason: Reviewer has not selected A or B yet; answer key remains hidden.
- answer key visible: `false`
- blind pack: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_blind_review_pack.json`
- decisions: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json`

#### Commands

- prepare_review_kit: `python3 scripts/yao.py output-review-kit --self`
- write_template: `python3 scripts/adjudicate_output_review.py --write-template`
- import_decisions: `python3 scripts/yao.py output-review-import --input <reviewer-decisions.json> --blind-review-attested --run-adjudication --self`
- adjudicate: `python3 scripts/yao.py output-review --self`
- refresh_review_studio: `python3 scripts/yao.py review-studio . --self`

#### Required Fields

- winner_variant: A or B after reading only the blind review pack.
- confidence: Optional number from 0 to 1.
- reason: Required rationale; do not reveal baseline or with-skill labels before adjudication.
- reviewer: Human reviewer name or review group at the decision-file top level.
- reviewed_at: Review date or timestamp at the decision-file top level.
- reviewer_attestation.blind_review_completed_before_answer_key: True only after the reviewer has completed choices before opening the answer key.
- reviewer_attestation.answer_key_not_opened_before_decisions: True only when the answer key was not opened before decisions were recorded.

#### Privacy Contract

- Do not paste raw private user data into the decision reason.
- Do not open the answer key before reviewer choices are recorded.
- Leave winner_variant blank when the reviewer is not ready to decide.

### prompt-injection-and-secret-boundary

- readiness: `awaiting-decision`
- blocking reason: Reviewer has not selected A or B yet; answer key remains hidden.
- answer key visible: `false`
- blind pack: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_blind_review_pack.json`
- decisions: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json`

#### Commands

- prepare_review_kit: `python3 scripts/yao.py output-review-kit --self`
- write_template: `python3 scripts/adjudicate_output_review.py --write-template`
- import_decisions: `python3 scripts/yao.py output-review-import --input <reviewer-decisions.json> --blind-review-attested --run-adjudication --self`
- adjudicate: `python3 scripts/yao.py output-review --self`
- refresh_review_studio: `python3 scripts/yao.py review-studio . --self`

#### Required Fields

- winner_variant: A or B after reading only the blind review pack.
- confidence: Optional number from 0 to 1.
- reason: Required rationale; do not reveal baseline or with-skill labels before adjudication.
- reviewer: Human reviewer name or review group at the decision-file top level.
- reviewed_at: Review date or timestamp at the decision-file top level.
- reviewer_attestation.blind_review_completed_before_answer_key: True only after the reviewer has completed choices before opening the answer key.
- reviewer_attestation.answer_key_not_opened_before_decisions: True only when the answer key was not opened before decisions were recorded.

#### Privacy Contract

- Do not paste raw private user data into the decision reason.
- Do not open the answer key before reviewer choices are recorded.
- Leave winner_variant blank when the reviewer is not ready to decide.

### five-channel-consistency

- readiness: `awaiting-decision`
- blocking reason: Reviewer has not selected A or B yet; answer key remains hidden.
- answer key visible: `false`
- blind pack: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_blind_review_pack.json`
- decisions: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json`

#### Commands

- prepare_review_kit: `python3 scripts/yao.py output-review-kit --self`
- write_template: `python3 scripts/adjudicate_output_review.py --write-template`
- import_decisions: `python3 scripts/yao.py output-review-import --input <reviewer-decisions.json> --blind-review-attested --run-adjudication --self`
- adjudicate: `python3 scripts/yao.py output-review --self`
- refresh_review_studio: `python3 scripts/yao.py review-studio . --self`

#### Required Fields

- winner_variant: A or B after reading only the blind review pack.
- confidence: Optional number from 0 to 1.
- reason: Required rationale; do not reveal baseline or with-skill labels before adjudication.
- reviewer: Human reviewer name or review group at the decision-file top level.
- reviewed_at: Review date or timestamp at the decision-file top level.
- reviewer_attestation.blind_review_completed_before_answer_key: True only after the reviewer has completed choices before opening the answer key.
- reviewer_attestation.answer_key_not_opened_before_decisions: True only when the answer key was not opened before decisions were recorded.

#### Privacy Contract

- Do not paste raw private user data into the decision reason.
- Do not open the answer key before reviewer choices are recorded.
- Leave winner_variant blank when the reviewer is not ready to decide.

### body-only-evidence-bypass

- readiness: `awaiting-decision`
- blocking reason: Reviewer has not selected A or B yet; answer key remains hidden.
- answer key visible: `false`
- blind pack: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_blind_review_pack.json`
- decisions: `D:\Documents\恒佳geo\GEOFlow-main\.agents\skills\hengjia-rubber-joint-content-agent\reports\output_review_decisions.json`

#### Commands

- prepare_review_kit: `python3 scripts/yao.py output-review-kit --self`
- write_template: `python3 scripts/adjudicate_output_review.py --write-template`
- import_decisions: `python3 scripts/yao.py output-review-import --input <reviewer-decisions.json> --blind-review-attested --run-adjudication --self`
- adjudicate: `python3 scripts/yao.py output-review --self`
- refresh_review_studio: `python3 scripts/yao.py review-studio . --self`

#### Required Fields

- winner_variant: A or B after reading only the blind review pack.
- confidence: Optional number from 0 to 1.
- reason: Required rationale; do not reveal baseline or with-skill labels before adjudication.
- reviewer: Human reviewer name or review group at the decision-file top level.
- reviewed_at: Review date or timestamp at the decision-file top level.
- reviewer_attestation.blind_review_completed_before_answer_key: True only after the reviewer has completed choices before opening the answer key.
- reviewer_attestation.answer_key_not_opened_before_decisions: True only when the answer key was not opened before decisions were recorded.

#### Privacy Contract

- Do not paste raw private user data into the decision reason.
- Do not open the answer key before reviewer choices are recorded.
- Leave winner_variant blank when the reviewer is not ready to decide.

## Next Fixes

- Keep the blind review pack separate from the answer key until decisions are recorded.
- Treat disagreement cases as prompts for rubric tuning or output improvement.
- Add model-executed holdout runs after this human adjudication harness is stable.
