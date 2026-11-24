# Controle de estoque hospitalar

Aplicação web em português para cadastrar medicamentos e materiais hospitalares, acompanhar o estoque e consultar seu histórico de movimentações. A interface identifica itens com estoque baixo ou próximos do vencimento e oferece relatórios para acompanhamento.

## Funcionalidades

- **Painel geral:** total de medicamentos e unidades, itens no limite ou abaixo do estoque mínimo, vencimentos próximos, avisos e movimentações recentes.
- **Medicamentos:** cadastro e edição de nome, princípio ativo, apresentação, categoria, unidade, quantidade, estoque mínimo, lote, validade e fornecedor.
- **Busca e filtros:** pesquisa por medicamento, princípio ativo, apresentação, lote ou fornecedor; filtros por categoria e situação do estoque.
- **Movimentações:** registro de entradas, saídas e ajustes, com responsável e observação. Saídas maiores que o saldo disponível não são aceitas.
- **Relatórios:** resumo por categoria, alertas e listagem completa do estoque. É possível imprimir o relatório ou exportar o inventário em CSV.
- **Interface responsiva:** navegação adaptada a telas menores.

### Situações do estoque

O sistema apresenta os itens como sem estoque, vencidos, próximos do vencimento, com estoque baixo ou adequados. O estoque baixo é identificado quando a quantidade é menor ou igual ao mínimo informado; vencimentos próximos consideram os próximos 90 dias.

## Tecnologias

- PHP 8.3 para servir a página e incluir os componentes PHP.
- HTML, CSS e JavaScript sem framework ou dependências de pacotes.
- SQLite no servidor para compartilhar os dados entre dispositivos.
- Docker Compose com PHP-FPM e Nginx para execução em contêineres.
- `estoque_hospital.sql` com o esquema SQLite de medicamentos e movimentações.

## Executar com Docker

Pré-requisitos: Docker instalado e em execução, com suporte ao comando `docker compose`.

Na pasta do projeto, execute:

```powershell
docker compose up -d --build
```

Abra [http://localhost:8080](http://localhost:8080) no computador que executa o Docker. O acesso HTTP está restrito ao próprio computador.

Para encerrar os contêineres:

```powershell
docker compose down
```

Os dados ficam no volume Docker `estoque_data` e continuam salvos após `docker compose down` ou a recriação dos contêineres. **Não use `docker compose down -v`** se quiser preservar o banco.

## Publicar com HTTPS

Para HTTPS público, é necessário ter um domínio apontado para o IP público do servidor e liberar as portas TCP 80 e 443 (e UDP 443, para HTTP/3) no firewall. No servidor, configure o domínio e inicie o proxy HTTPS:

```powershell
$env:HOSPITAL_DOMAIN = "estoque.seudominio.com"
docker compose --profile https up -d --build
```

O Caddy solicita e renova automaticamente um certificado TLS para o domínio. Acesse `https://estoque.seudominio.com`. O proxy HTTPS encaminha as requisições ao Nginx, enquanto a porta local `8080` continua acessível somente no próprio servidor. Como não há login, não exponha a aplicação à internet nem use dados sensíveis.

## Executar sem Docker

Com PHP e o driver PDO SQLite instalados, abra um terminal PowerShell na pasta do projeto, configure um caminho gravável fora da pasta pública e inicie o servidor:

```powershell
$env:HOSPITAL_DB_PATH = "$env:LOCALAPPDATA\Hospital\estoque.sqlite"
php -S 127.0.0.1:8000
```

Acesse [http://127.0.0.1:8000](http://127.0.0.1:8000).

## Como os dados são armazenados

Medicamentos e movimentações são salvos no banco SQLite persistente do servidor. Todos os dispositivos que acessam o mesmo servidor compartilham esses dados. O sistema não exige login; proteja o acesso à rede e não publique a aplicação na internet. Se houver dados antigos no `localStorage` deste navegador e o banco estiver vazio, eles são importados automaticamente uma única vez.

Mantenha o domínio e o servidor disponíveis para o acesso remoto. O repositório não contém o banco SQLite nem os dados de certificado do Caddy.

## Estrutura do projeto

| Arquivo | Responsabilidade |
| --- | --- |
| `index.php` | Página de entrada; inclui o cabeçalho, a navegação e os modais. |
| `header.php` | Metadados HTML, título da página e referências ao CSS e JavaScript. |
| `sidebar.php` | Navegação lateral e conteúdo das páginas de painel, estoque, movimentações e relatórios. |
| `modal.php` | Formulários para cadastro/edição de medicamentos e registro de movimentações. |
| `app.js` | Lógica da interface, validação, cálculos, renderização e comunicação com a API. |
| `styles.css` | Estilos, estados visuais, responsividade e ajustes de impressão. |
| `docker-compose.yml` | Serviços PHP-FPM e Nginx e configuração da porta local. |
| `Dockerfile` | Instala o driver SQLite do PHP para o serviço da aplicação. |
| `api.php` | API de leitura e gravação do banco SQLite compartilhado. |
| `db.php` | Inicialização do banco de dados SQLite. |
| `Caddyfile` | Proxy com emissão automática de HTTPS para o domínio configurado. |
| `default.conf` | Configuração do Nginx para encaminhar requisições PHP ao PHP-FPM. |
| `estoque_hospital.sql` | Esquema SQLite aplicado automaticamente pela API. |

## Observações

- A quantidade existente de um medicamento é alterada por uma movimentação; ao editar o cadastro, a quantidade fica bloqueada.
- O sistema impede o cadastro de medicamentos com o mesmo nome e apresentação.
- A exclusão de um medicamento pede confirmação. As movimentações já registradas são mantidas no histórico do banco.
- A exportação CSV é gerada no navegador, em formato separado por ponto e vírgula e com codificação adequada para acentos.
