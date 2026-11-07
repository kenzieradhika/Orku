# Release evidence policy

Store retained raw run outputs outside the repository by default. Every
evidence report should identify commit, runtime/image digest, config, duration,
raw observations, errors, and limitations. Synthetic fixtures only; remove
credentials and user data. Distinguish unit/smoke results from benchmark,
soak, deployment, and native-hardware evidence. Never turn unavailable or
unmeasured data into zero or a universal performance claim.

Evidence index: `docs/release-evidence/hardware-matrix.md` and
`docs/stable-release-checklist.md`. Release readiness remains blocked until all
applicable gates close with linked reproducible evidence.
