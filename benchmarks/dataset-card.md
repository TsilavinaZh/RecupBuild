---
license: cc-by-4.0
pretty_name: RecupBuild scan benchmark
task_categories:
  - object-detection
tags:
  - measurement
  - reclaimed-materials
  - woodworking
  - sustainability
  - synthetic
size_categories:
  - n<1K
---

# RecupBuild scan benchmark

Overhead photos of reclaimed wood and sheet metal next to an A4 sheet, with the **true size of every
piece in millimetres**. It is the benchmark of [RecupBuild](https://github.com/TsilavinaZh/RecupBuild),
an app that turns one photo of reclaimed material into a cutting plan, built for the Meta Global AI
Hackathon 2026.

It answers a precise question: given one photo and an A4 sheet as the only scale, how close can a
vision pipeline get to the real size of each piece?

## Contents

{{scenes}} scenes of 1600 x 1100 pixels, {{pieces}} pieces:

| Kind | Pieces |
| --- | --- |
{{kinds}}

## How the scenes are made

A tape measure cannot be held to a picture, so these images are **composed, not photographed**. Real
wood and metal textures are cropped from one real photo, scaled to a chosen size, turned by a small
angle, given a soft shadow and laid on real concrete from the same photo, next to a white sheet with
the proportions of A4. Every scene uses the same true scale, and rebuilds identically from its seed.

## Fields

`train/metadata.jsonl` has one line per image:

| Field | Meaning |
| --- | --- |
| `file_name` | the image in `train/` |
| `seed` | rebuilds the scene with the generator |
| `width`, `height` | size of the image in pixels |
| `mm_per_px` | the true scale of the scene |
| `a4.x`, `a4.y` | centre of the A4 sheet, in pixels, lying upright and unrotated |
| `pieces[].kind` | `plank`, `pallet_board`, `batten`, `plywood` or `sheet_metal` |
| `pieces[].length_mm`, `width_mm` | true size of the piece, long side first |
| `pieces[].x`, `y` | centre of the piece, in pixels |
| `pieces[].rotation_deg` | counter-clockwise angle of the long side from vertical, -90 to 90 |

## Baseline results

Measured with the real models on the first {{benchmark_scenes}} scenes (seeds {{first}} to {{benchmark_last}}), {{benchmark_pieces}} pieces:

| Metric | Muse Spark alone | Muse Spark + SAM 3.1 |
| --- | --- | --- |
| Pieces found (F1) | {{spark_f1}} | {{sam_f1}} |
| Length error (mean absolute) | {{spark_length}} mm | {{sam_length}} mm |
| Width error (mean absolute) | {{spark_width}} mm | {{sam_width}} mm |
| A4 scale error | {{spark_scale}} % | {{sam_scale}} % |

The code is in [`benchmarks/`](https://github.com/TsilavinaZh/RecupBuild/tree/master/benchmarks).

## Limits

- The images are synthetic composites: the textures all come from one photo, so a model can recognise them.
- They are easier than a real photo: pieces never touch, the camera is perfectly overhead and the light is even.
- Thickness is not labelled, since it cannot be read from above.
- A score here is a fair way to compare pipelines, not the precision to expect on a real heap.
