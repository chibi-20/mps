# MPS System Flow

This chart shows how assessments move through MPS, from setup and student results to review and school analytics. It includes the three ways results enter the system: teacher encoding, the MPS Scanner, and ZipGrade CSV.

```mermaid
flowchart TD
    START([Start]) --> ROLE{Who is using MPS?}

    ROLE -->|Admin| ADMIN[Admin dashboard]
    ROLE -->|Teacher| TEACHER[Teacher dashboard]

    ADMIN --> BLUEPRINT[Set up assessment blueprint<br/>subject, term, items, competencies]
    BLUEPRINT --> SHARED[Assessment available to assigned teachers]

    TEACHER --> ASSESS{Choose an assessment}
    SHARED --> ASSESS
    ASSESS -->|Existing or shared| SECTIONS[Choose assigned sections]
    ASSESS -->|Teacher-created| CREATE[Create assessment]
    CREATE --> SECTIONS

    SECTIONS --> INPUT{How will results be entered?}
    INPUT -->|Type results| ENCODE[Enter score frequencies<br/>and item-correct counts]
    INPUT -->|Scan paper| SCANSETUP[Set and save answer key in MPS]
    SCANSETUP --> SIGNIN[Sign in to MPS Scanner]
    SIGNIN --> SHEET[Scan four-subject answer sheet]
    SHEET --> REVIEW[Review student ID, section,<br/>subject and answers]
    REVIEW --> VALID{IDs, answers and<br/>assessment key valid?}
    VALID -->|No| REVIEW
    VALID -->|Yes| CONNECT{Online?}
    CONNECT -->|Yes| SCANAPI[Send answers to MPS]
    CONNECT -->|No / API unavailable| OUTBOX[Queue scans on device]
    OUTBOX --> SYNC[Reconnect and sync]
    SYNC --> SCANAPI
    SCANAPI --> SCORE[MPS checks access and answer key,<br/>calculates score and correctness]
    SCORE --> AGGREGATE[Save student answers;<br/>rebuild section score and item summaries]

    INPUT -->|Import ZipGrade| CSV[Upload ZipGrade CSV<br/>preview, then confirm]
    CSV --> AGGREGATE
    ENCODE --> SAVE[Save draft or submit]
    AGGREGATE --> SAVE
    SAVE --> STATUS{Submit for review?}
    STATUS -->|No| TEACHER
    STATUS -->|Yes| ADMINREVIEW[Admin reviews submission]
    ADMINREVIEW --> DECISION{Approve or return?}
    DECISION -->|Return with remarks| FIX[Teacher corrects results]
    FIX --> SAVE
    DECISION -->|Approve| APPROVED[Approved results]
    APPROVED --> ANALYTICS[Admin analytics and reports<br/>MPS, mastery bands, item and competency analysis]
    ANALYTICS --> EXPORT[View dashboard or export Excel]

    classDef people fill:#f5e9ed,stroke:#76283b,color:#32151c
    classDef process fill:#edf4fb,stroke:#356b91,color:#142b3b
    classDef data fill:#e9f4ec,stroke:#438052,color:#18351f
    classDef decision fill:#fff4d8,stroke:#b08020,color:#382909
    class ADMIN,TEACHER,ADMINREVIEW people
    class BLUEPRINT,SECTIONS,CREATE,SCANSETUP,SIGNIN,SHEET,REVIEW,SCANAPI,SCORE,SYNC,CSV,ENCODE,SAVE,FIX,ANALYTICS,EXPORT process
    class SHARED,OUTBOX,AGGREGATE,APPROVED data
    class ROLE,ASSESS,INPUT,VALID,CONNECT,STATUS,DECISION decision
```

## How to read it

- **Assessment setup:** Admins can publish a shared blueprint; teachers may also create an assessment where that workflow is available.
- **Results entry:** Teachers can encode summaries directly, import a ZipGrade CSV, or scan individual answer sheets. Scanner and ZipGrade data cannot both be used for the same assessment section at once.
- **Scanner path:** The device reads and lets the teacher correct the sheet. Online scans go to MPS; offline scans wait in an on-device queue until sync. MPS calculates each student's score from the saved answer key and updates the same summaries used by the other entry paths.
- **Review and reporting:** Teachers submit results. Admins approve them or return them with remarks. Approved data is used in admin analytics and exports.

The scanner also supports an older generic document-reading screen; that path is a local preview/mock workflow and is not part of assessment submission.
