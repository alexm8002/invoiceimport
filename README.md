# InvoiceImport - Module Dolibarr

## Description

Module Dolibarr permettant l'import automatique de factures fournisseurs reçues par email.

Le module :
- se connecte à une boîte IMAP
- récupère les emails non lus
- extrait les pièces jointes PDF
- analyse les factures via un parser Python
- crée automatiquement les factures fournisseurs dans Dolibarr

---

## Fonctionnement

1. Connexion IMAP à une boîte email (ex: invoice@...)
2. Lecture des emails non lus
3. Extraction des fichiers PDF
4. Analyse via script Python (`pdfplumber`)
5. Création de la facture fournisseur
6. Marquage de l'email comme lu

---
##  Installation 

### Méthode recommandée (interface Dolibarr)

1. Aller dans : Accueil → Configuration → Modules → Déployer/Installer un module externe
2. Charger l’archive du module (`.zip`)
3. Installer puis activer **InvoiceImport**
4. Aller dans : Facturation → Imports factures → Configuration
5. Configurer l’accès IMAP
6. C’est prêt ✅

### Méthode alternative (manuelle)

### 1. Copier le module
Déposer le dossier dans : htdocs/custom/invoiceimport

---
### 2. Activer le module
Dans Dolibarr : Accueil → Configuration → Modules

Activer : InvoiceImport

---
### 3. Installation automatique Python

Lors de l’activation, le module :

- crée un environnement Python virtuel (`venv`)
- installe automatiquement les dépendances :
  - pdfplumber
  - pdfminer
  - pillow

Aucune configuration Python manuelle nécessaire.

---
## Prérequis serveur

- PHP avec extension IMAP activée
- Python3 disponible sur le serveur (chemin standard ou accessible via `which python3`)
- Accès sortant Internet (pour installer les dépendances Python)

---
## Configuration

Menu : Facturation → Imports factures → Configuration

---
## Tâche planifiée (CRON)

Le module crée automatiquement une tâche CRON Dolibarr :

- Exécution toutes les 5 minutes
- Traitement des emails non lus
