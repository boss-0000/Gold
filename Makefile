# Atajos. Requiere Docker. (Los comandos crudos están en el README.)
.PHONY: up down demo demo-oversell test shell logs

up:            ## Construye y levanta la pila (MySQL 8 + Redis + app)
	docker compose up -d --build

down:          ## Apaga y borra volúmenes
	docker compose down -v

demo:          ## Escenario doble-cobro (idempotencia/timeout), antes vs después
	docker compose exec app php artisan demo:hammer --scenario=duplicate --mode=both

demo-oversell: ## Escenario sobreventa (bloqueo de fila), antes vs después
	docker compose exec app php artisan demo:hammer --scenario=oversell --mode=both --workers=30

test:          ## Corre la suite de tests
	docker compose exec app php artisan test

shell:         ## Abre una shell en el contenedor de la app
	docker compose exec app bash

logs:          ## Sigue los logs
	docker compose logs -f app
