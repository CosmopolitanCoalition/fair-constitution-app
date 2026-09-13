# Deferred human checks

Updated 13 September 2026. **Only checks requiring human participation, judgment or physical-device experience appear here.** They do not keep internally completed development open. All required multi-user development checks use simulated participants. These human checks remain unperformed until evidence exists.

| Human-dependent check | Dependency | What people will assess |
|---|---|---|
| Real microphone, camera, headphones and screen sharing on separate physical devices | Attendee hardware/browser combinations and participants available | Physical device selection/permission prompts, actual audio/video quality, comfort and recovery on that hardware. Synthetic transport and automated permission/error checks remain internal. |
| Human demo dress rehearsal | Final scenarios and willing participants | Whether people can follow the presentation and complete tasks; clarity, pacing and comprehension. The required final development rehearsal uses simulated participants. |
| Human usability and assistive-device feedback | Internally checked navigation, keyboard/focus, screen-reader semantics, narrow layouts and media alternatives | Practical use with personal assistive devices and actual user needs. Feedback complements internal accessibility checks; it does not replace them. |
| Human review of educational explanations and translations | Settled teaching content and technical language coverage | Explanation quality, translation naturalness and learner comprehension. Missing strings, wrong action links and missing media alternatives remain internal checks/build defects. |

The [internal review register](DEMO_REVIEW_REGISTER.md) contains simulated civic/economic/room journeys, automated language/accessibility/media checks, fresh installation, volunteer mesh joining, scale measurements, final simulated rehearsal and conference-host deployment/connectivity verification. Remote-host access is a dependency, not a reason to label an automatable check human-only. An “actual member” in a scenario can be a simulated identity with the actual fixture office.

Record evidence when a human check is performed. Move a passed scope to [completed work](DEMO_COMPLETED_WORK.md). Put a concrete defect requiring development on the [build punch list](DEMO_ACTION_PLAN.md). Completion of the development list does not claim unperformed physical-device or human checks passed.
