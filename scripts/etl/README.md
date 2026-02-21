# ETL Scripts

This directory contains Python scripts for the geospatial data pipeline:

- `import_geoboundaries.py` — Downloads and imports boundary polygons from geoBoundaries API
- `import_worldpop.py` — Downloads WorldPop raster data and attributes population counts to boundaries  
- `run_skater.py` — Runs SKATER regionalization algorithm to generate initial legislative districts
- `seed_database.py` — Master script that runs the full pipeline in order

## Running

From the project root:

```bash
# Enter the ETL container
docker compose exec etl bash

# Run the full pipeline
python seed_database.py

# Or run individual steps
python import_geoboundaries.py
python import_worldpop.py
python run_skater.py
```

Downloaded geodata is stored in the `etl_geodata` Docker volume at `/data` inside the container.
This volume persists across container restarts so you don't re-download everything each time.
