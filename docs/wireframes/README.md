# Chapter 4 figure sources

Each `.dc.html` file is one artboard; `canvas.json` lays them out and names the
pages. They are the sources for Figures 4.1–4.6 and 4.8.

| File | Figure |
|---|---|
| `Main.dc.html` | 4.1 Public submission interface |
| `VerdictBands.dc.html` | 4.2 Verdict display in three risk bands |
| `VerifiedInstitution.dc.html` | 4.3 Verified institution display |
| `ModerationQueue.dc.html` | 4.4 Administrator moderation queue |
| `ContextDiagram.dc.html` | 4.5 Context diagram (level 0) |
| `DataFlowLevel1.dc.html` | 4.6 Data flow diagram, level 1 |
| `SecurityFramework.dc.html` | 4.8 Security system framework |

`DirectionA/B/C.dc.html` are the three visual directions the interface design
was chosen from. B — warm reassurance — is the one that shipped. They are kept
because the choice and its alternatives are worth showing in the report.

Figure 4.7, the ERD, is not here; it was produced separately.

## Viewing and exporting

The assembled canvas is published as an artifact and opens in a browser, where
each artboard exports to PNG or the set exports to one PDF:

<https://claude.ai/code/artifact/893650b0-e203-4465-9403-0dde82e9f2e4>

The assembled file itself is **not** committed. It is a 2.2 MB build output
produced from these sources, and every rebuild writes a whole new copy, so
tracking it would grow the repository each time a figure changed. These sources
plus the published canvas are what matter; the built file is disposable.

## Style

Low-fi and structural by intent. Figures 4.9–4.15 are screenshots of the built
system, so these show layout decisions rather than final visual design: grey
bars stand in for body content, and real text appears only on controls, tabs
and labels. Every string is copy the system actually ships, so a wireframe and
its screenshot cannot contradict each other.
