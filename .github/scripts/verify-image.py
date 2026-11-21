"""Check the immutable release image reference before creating a GitHub Release."""

import json
from pathlib import Path
import sys


def verify(manifest, expected_sha):
    if manifest.get("annotations", {}).get("org.opencontainers.image.revision") != expected_sha:
        raise ValueError("image revision does not match the release tag")

    platforms = {
        (item.get("platform", {}).get("os"), item.get("platform", {}).get("architecture"))
        for item in manifest.get("manifests", [])
    }
    if not {("linux", "amd64"), ("linux", "arm64")} <= platforms:
        raise ValueError("image is missing amd64 or arm64")


if __name__ == "__main__":
    try:
        verify(json.loads(Path(sys.argv[1]).read_text()), sys.argv[2])
    except (ValueError, OSError, IndexError) as error:
        sys.exit(f"Invalid release image: {error}")
