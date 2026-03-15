# projets_code

Dépôt parent regroupant les projets de développement.

## Structure

```
projets_code/
├── IOT_n3/              # Projet IoT (sous-module)
│   ├── serveur/         # Backend PHP
│   └── firmwires/       # Firmwares ESP32
└── moodle/
    └── index_olution/   # Thème Moodle personnalisé (sous-module)
```

## Clonage

Pour récupérer ce dépôt avec tous les sous-modules :

```bash
git clone --recurse-submodules https://github.com/oliviera999/projets_code.git
cd projets_code
```

Si le dépôt est déjà cloné sans les sous-modules :

```bash
git submodule update --init --recursive
```

## Sous-modules

| Module | URL |
|--------|-----|
| IOT_n3 | https://github.com/oliviera999/IOT_n3.git |
| moodle/index_olution | https://github.com/oliviera999/index_olution.git |
