# Benchmarks

Two evaluations of RecupBuild, with the numbers they actually produced. The raw results are in
[`results/`](results/).

## Scan accuracy: does SAM 3.1 earn its place?

```sh
php benchmarks/scan.php 6     # real calls to the Meta Model API (MODEL_API_KEY in .env.php)
```

A tape measure cannot be held to a picture, so [`Scenes.php`](Scenes.php) composes photos of known
sizes: real wood and metal cut from `demo/pile.jpg`, laid on real concrete at chosen sizes and small
angles, each with a shadow, next to an A4 sheet. Every scene is rebuilt identically from its seed.
Each one is read twice, by Muse Spark alone and by Muse Spark with SAM 3.1, then scored against the
truth.

Results on 6 scenes and 46 pieces:

| Metric | Muse Spark alone | Muse Spark + SAM 3.1 |
| --- | --- | --- |
| Precision | 1.000 | 1.000 |
| Recall | 1.000 | 1.000 |
| F1 | 1.000 | 1.000 |
| Length error (mean absolute) | 41.5 mm | **1.8 mm** |
| Width error (mean absolute) | 15.2 mm | **2.5 mm** |
| Size error (mean, relative) | 8.0 % | **2.1 %** |
| A4 scale error | 6.37 % | **0.86 %** |
| Mean time per photo | 20.7 s | 20.7 s + 13.5 s |

Muse Spark finds every piece; SAM makes the measurements about 23 times closer on length. That is
what the extra 13.5 seconds buy.

**Limits of this benchmark.** The scenes are easier than a real photo: the pieces do not touch, the
camera is perfectly overhead, and the light is even. Six scenes are a small sample. Read these
numbers as a fair comparison of the two pipelines on the same images, not as the precision to expect
on a real heap, where the plan page still asks the user to check every size before cutting.

## Cutting engine: is every plan really buildable?

```sh
php benchmarks/solver.php     # no model, runs in CI
```

Every project (the 4 built-in ones and 4 invented by Muse Spark) is planned on the demo inventory and
on 40 seeded random inventories. Each plan is then checked from scratch, without trusting the engine:
every part must sit inside its piece, and two parts on the same piece must stay a saw kerf apart. A
single fault fails the run, and with it the CI.

| Metric | Result |
| --- | --- |
| Plans attempted | 328, on 41 inventories |
| Plans found | 182 |
| Parts placed and checked | 1,144 |
| **Faults** | **0** |
| Wood used, median share of the pieces cut | 43.0 % |
| Solve time, median | 0.12 ms |
| Solve time, slowest | 3.41 ms |

A project with no plan is a valid answer: on these inventories some projects simply do not fit, and
the app then shows or invents others. The 43 % counts the whole of each piece used, so the offcuts
that remain are often still usable.

## Dataset

The scenes and their true sizes are published on the Hugging Face Hub:
**[tsilavinaZh/recupbuild-scan-benchmark](https://huggingface.co/datasets/tsilavinaZh/recupbuild-scan-benchmark)**.

```sh
php benchmarks/export_dataset.php     # 50 scenes, 387 pieces, into ../recupbuild-scan-benchmark
```

Exports the scenes with their true sizes, in the format of the Hugging Face Hub (`train/`,
`train/metadata.jsonl`), and a dataset card filled with the results above
([`dataset-card.md`](dataset-card.md)). No model is called.

