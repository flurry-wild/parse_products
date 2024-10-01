install:
	cp .env.example .env
	docker-compose build
	docker-compose up -d
	docker-compose exec app composer install
	sudo chmod -R 777 ./project/storage ./project/bootstrap
	sudo chown -R ${USER} ./project
	cp project/.env.example project/.env
	docker-compose exec app php artisan key:generate
	docker-compose exec app php artisan migrate
parse:
	docker-compose exec app php artisan ozon:parse
