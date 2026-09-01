# Artifact Design Profile

Skill: `hengjia-rubber-joint-content-agent`
Design system: `review studio`

## Primary Artifact Direction

**Code, CLI, or implementation guide**

Execution-focused technical artifact with environment assumptions, copyable commands, expected outputs, and side effects made explicit.

## Matched Artifact Families

### Code, CLI, or implementation guide
- Matched keywords: code, script, api, command, 脚本, 接口
- Score: `6`
- Direction: Execution-focused technical artifact with environment assumptions, copyable commands, expected outputs, and side effects made explicit.

### Report or brief
- Matched keywords: report, 报告
- Score: `2`
- Direction: High-trust editorial report with a clear first-screen thesis, compact evidence blocks, and decisions separated from supporting detail.

### Screenshot or visual evidence
- Matched keywords: screenshot, 图片
- Score: `2`
- Direction: Evidence-led visual artifact that records source, viewport, crop intent, and the exact region the reader should inspect.

### Review viewer
- Matched keywords: review
- Score: `1`
- Direction: Side-by-side reviewer studio with explicit tradeoffs, evidence readiness, and fast paths for approving, blocking, or requesting one focused fix.

## Layout Patterns To Prefer

- prerequisites
- commands
- expected output
- failure handling
- rollback or cleanup
- thesis
- evidence blocks
- decision table

## Design Tokens

### Type
- Use a distinctive display face or serif for major claims when the artifact is editorial.
- Use a restrained sans for dense body text and technical details.
- Use mono only for metadata, paths, commands, labels, and evidence tags.

### Color
- Choose colors from the artifact's domain, brand, or evidence mood.
- Do not default to Kami parchment, purple gradients, or generic SaaS blue unless the content justifies it.
- Keep accent color limited to decisions, active states, risk, or section anchors.

### Spacing
- Prefer clear grid rhythm over floating decorative cards.
- Increase whitespace around decisions and shrink whitespace around supporting metadata.
- Split dense content instead of shrinking type or adding scroll traps.

### Components
- Use cards for grouped evidence, tables for comparisons, callouts for decisions, and timelines for sequence.
- Avoid cards inside cards.
- Keep reviewer-only detail visible but visually quieter than user-facing guidance.

## Quality Gates

- Name the working directory and required inputs for commands.
- Mark destructive, networked, or external side-effect operations.
- Prefer the smallest runnable snippet over broad framework scaffolding.
- Keep the first screen useful without requiring the reader to parse every detail.
- Use tables only for comparisons; move explanations below the table.
- Keep source notes readable without flooding the body with markers.
- Never invent missing screenshots or visual states.
- Record source, viewport, and crop intent.

## Anti-Patterns

- Do not copy Kami's fixed parchment background as a default.
- Do not use generic purple gradients, glass cards, or stock SaaS hero sections unless the content calls for them.
- Do not let Markdown tables become the default shape for every comparison or explanation.
- Do not turn reviewer evidence into user-facing clutter.
- Do not invent screenshots, citations, charts, or UI states.

## Reviewer Note

Use this profile to judge whether the generated artifacts feel designed for their job, not merely rendered.
