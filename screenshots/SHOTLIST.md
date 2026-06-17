# Screenshot shot list

Capture these against a running OJS 3.4 install with the plugin enabled, then
save them in this folder with the exact filenames below. The README references
the **bold** ones; the others are optional but nice to have.

| File | What to capture |
|---|---|
| `01-enable-plugin.png` | Settings → Website → Plugins, with **CiteOrbit Reference Validation** enabled (checkbox ticked). |
| **`02-settings.png`** | The plugin Settings modal: the masked **CiteOrbit API key** field and the **Default citation style** dropdown. |
| **`03-validate-references-button.png`** | Workflow → Publication → **References** tab showing the **Validate with CiteOrbit** button below the citations list. |
| `04-confirm-references.png` | The confirmation dialog ("Send N references to CiteOrbit…"). |
| `05-references-report-link.png` | The References tab after a check, showing the **Open CiteOrbit report** link next to the button. |
| **`06-validate-file-action.png`** | A workflow file grid (e.g. Production) with a PDF/DOCX row's actions open, showing **Validate with CiteOrbit** (and, after validating, **Open CiteOrbit report**). |
| **`07-report.png`** | The CiteOrbit report page (`/check-references/by-check/<id>`) with per-reference results. |

Tips:
- Use a journal with a few real references so the report looks meaningful.
- Crop to the relevant panel; a 2:1-ish landscape crop reads best in the README.
- Blur or use a throwaway key — though the field is masked, the key can be
  revealed; don't expose a live `cob_live_` value in a screenshot.
