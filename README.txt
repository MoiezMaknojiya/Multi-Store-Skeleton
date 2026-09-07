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
