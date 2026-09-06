# Module TAE - Simulation / Débogage

Module IANSEO pour générer automatiquement des flèches pour tous les archers d'un tournoi.

## 🚀 Fonctionnalités

- ✅ **Ajouter des volées** : 1, 2, ou plus de flèches pour chaque archer
- ✅ **Compléter la session** : Remplir automatiquement jusqu'au nombre max de flèches
- ✅ **Réinitialiser** : Effacer toutes les flèches
- ✅ **Filtres** : Par départ (1/2), par type d'archer (CO/Spot), par distance (D1/D2)
- ✅ **Détection auto** : INDOOR (3 flèches/volée) vs OUTDOOR (6 flèches/volée)
- ✅ **Types de flèches** : Aléatoire (réaliste), tous des 10, tous des 9, etc.
- ✅ **Sauvegarde** : Bouton de sauvegarde avant modification
- ✅ **Journal** : Logs détaillés des opérations

## 📋 Installation

Le module est dans `Modules/Custom/TAE/` :

```
TAE/
├── menu.php           Enregistrement dans le menu
├── simulate/
│   ├── index.php      Interface principale
│   ├── ajax.php       Backend (traitement des actions)
│   └── .gitkeep
└── README.md
```

Le module s'enregistre automatiquement dans le menu **Modules** du tournoi.

## 🛠️ Architecture

### Frontend (`simulate/index.php`)
- Interface jQuery/Bootstrap
- Appels AJAX vers `ajax.php`
- Gestion UI (logs, table, onglets)

### Backend (`simulate/ajax.php`)
- Utilise **les connexions IANSEO existantes** (`safe_r_sql`, `safe_w_sql`)
- Pas de `mysqli_connect()` direct → portable sur tout VPS
- Actions : `get_data`, `add_arrows`, `reset_arrows`, `complete_session`

## 💡 Utilisation

1. **Ouvrir un tournoi** dans IANSEO
2. **Menu → Modules → Simulation / Débogage**
3. **Configurer** :
   - Sélectionner les archers (tous, départ, type)
   - Type de flèche (aléatoire, 10, 9, 8)
   - Distance (D1, D2, ou les deux)
4. **Sauvegarder** (important !)
5. **Cliquer** : 1 volée / Compléter / Réinitialiser

## ⚙️ Détection auto du type de tournoi

```php
INDOOR  = ToTypeName contient "indoor" ou = 1
          → 3 flèches par volée, max 30 par distance
OUTDOOR = Par défaut
          → 6 flèches par volée, max 36 par distance
```

## 🔐 Permissions

Accès requis : **Participants - Lecture/Écriture** (AclParticipants, AclReadWrite)

## 📝 Notes

- **TOUJOURS sauvegarder avant de modifier** (bouton rouge)
- Les flèches sont générées selon la division/arme détectée
- Les stats (hits, golds, xnine) sont recalculées automatiquement
- Le module ne crée pas de table perso, utilise uniquement `Qualifications`

## 🐛 Dépannage

**Erreur "Erreur connexion DB"** (ancien module)
→ Utiliser ce nouveau module qui respecte l'architecture IANSEO

**Tableau vide**
→ Vérifier qu'un tournoi est ouvert et qu'il y a des archers inscrits

**AJAX en erreur**
→ Vérifier que `ajax.php` est dans le même dossier
→ Vérifier les logs du navigateur (F12 → Console)

## 🔄 Mise à jour

Pour mettre à jour sur le VPS :
1. Push le code sur GitHub
2. Utiliser le bouton "Mettre à jour le Addon" dans IANSEO
3. Le module se met à jour automatiquement

---

**Développé pour TAE** — Archerie francophone 🏹
