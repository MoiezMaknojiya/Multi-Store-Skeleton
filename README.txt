npx repomix@latest --split-output 1mb

php artisan migrate

php artisan db:seed

<!-- For Dev -->
npm run dev

<!-- For Production -->
npm run build

<!-- Scheduler (deploy): activity-log yearly maintenance -->
<!-- * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1 -->

<!-- Browser tests: create .env.dusk locally (APP_ENV=dusk, sqlite database/dusk.sqlite, its own APP_KEY) -->

<!-- Uploaded media/channel files are served from the public disk: required on every fresh install -->
php artisan storage:link

<!-- Ad Builder: four finished example ads in one store (safe to run again; --no-fonts works offline) -->
php artisan builder:examples {store_id}

<!-- Tests: backend (816) then browser (106). Dusk swaps .env — never run both at once, and close every player tab on localhost:8000 first -->
php artisan test --parallel --compact
php artisan dusk

<!-- Code style -->
vendor/bin/pint --dirty --format agent
