"""Feature extraction shared by training and serving.

Both `training/train_*.py` and the Flask predictors import from this package.
That is deliberate and load-bearing: keeping two copies of the extraction code
is the most common way an ML project silently breaks, because the model then
sees differently-computed features at serving time than it was trained on and
degrades without anything appearing to fail.
"""
