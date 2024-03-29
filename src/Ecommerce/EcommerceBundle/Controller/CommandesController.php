<?php

namespace Ecommerce\EcommerceBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Ecommerce\EcommerceBundle\Entity\UtilisateursAdresses;
use Ecommerce\EcommerceBundle\Entity\Commandes;
use Ecommerce\EcommerceBundle\Entity\Produits;

class CommandesController extends Controller {

    public function facture() {
        $em = $this->getDoctrine()->getManager();
        $generator = $this->container->get('security.secure_random');
        $session = $this->getRequest()->getSession();
        $adresse = $session->get('adresse');
        $panier = $session->get('panier');
        $commande = array();
        $totalHT = 0;
        $totalTVA = 0;

        $facturation = $em->getRepository('EcommerceBundle:UtilisateursAdresses')->find($adresse['facturation']);
        $livraison = $em->getRepository('EcommerceBundle:UtilisateursAdresses')->find($adresse['livraison']);
        $produits = $em->getRepository('EcommerceBundle:Produits')->findArray(array_keys($session->get('panier')));

        foreach ($produits as $produit) {
            $prixHT = ($produit->getPrix() * $panier[$produit->getId()]);
            $prixTTC = ($produit->getPrix() * $panier[$produit->getId()] / $produit->getTva()->getMultiplicate());
            $totalHT += $prixHT;

            if (!isset($commande['tva'][$produit->getTva()->getValeur() . '%']))
                $commande['tva'][$produit->getTva()->getValeur() . '%'] = $prixTTC - $prixHT;
            else
                $commande['tva'][$produit->getTva()->getValeur() . '%'] += $prixTTC - $prixHT;

            $totalTVA += $prixTTC - $prixHT;


            $commande['produit'][$produit->getId()] = array('reference' => $produit->getNom(),
                'quantite' => $panier[$produit->getId()],
                'prixHT' => $produit->getPrix(),
                'prixTTC' => $produit->getPrix() / $produit->getTva()->getMultiplicate());
        }

        $commande['livraison'] = array('nom' => $livraison->getNom(),
            'prenom' => $livraison->getPrenom(),
            'telephone' => $livraison->getTelephone(),
            'adresse' => $livraison->getAdresse(),
            'cp' => $livraison->getCp(),
            'ville' => $livraison->getVille(),
            'pays' => $livraison->getPays(),
            'complement' => $livraison->getComplement());

        $commande['facturation'] = array('nom' => $facturation->getNom(),
            'prenom' => $facturation->getPrenom(),
            'telephone' => $facturation->getTelephone(),
            'adresse' => $facturation->getAdresse(),
            'cp' => $facturation->getCp(),
            'ville' => $facturation->getVille(),
            'pays' => $facturation->getPays(),
            'complement' => $facturation->getComplement());

        $commande['prixHT'] = $totalHT;
        $commande['prixTTC'] = $totalHT + $totalTVA;
        $commande['token'] = bin2hex($generator->nextBytes(20));

        return $commande;
    }

    public function prepareCommandeAction() {
        $session = $this->getRequest()->getSession();
        $em = $this->getDoctrine()->getManager();

        if (!$session->has('commande'))
            $commande = new Commandes();
        else
            $commande = $em->getRepository('EcommerceBundle:Commandes')->find($session->get('commande'));

        $commande->setDate(new \DateTime());
        $commande->setUtilisateur($this->container->get('security.context')->getToken()->getUser());
        $commande->setValider(0);
        $commande->setReference(0);
        $commande->setCommande($this->facture());

        if (!$session->has('commande')) {
            $em->persist($commande);
            $session->set('commande', $commande);
        }

        $em->flush();

        return new Response($commande->getId());
    }

    /*
     * Cette méthode remplace l'api banque.
     */

    public function validationCommandeAction($id) {
        $em = $this->getDoctrine()->getManager();
        $commande = $em->getRepository('EcommerceBundle:Commandes')->find($id);

        if (!$commande || $commande->getValider() == 1)
            throw $this->createNotFoundException('La commande n\'existe pas');

        $commande->setValider(1);
        $commande->setReference($this->container->get('setNewReference')->reference()); //Service
        $em->flush();

        $session = $this->getRequest()->getSession();
        $session->remove('adresse');
        $session->remove('panier');
        $session->remove('commande');


        // Ici le mail de validation
        $message = \Swift_Message::newInstance()
                ->setSubject('Confirmation de votre commande sur bayamsalam.cm')
                ->setFrom(array('fotsingdieudonne@gmail.com' => "bayamsalam.cm"))
                ->setTo($commande->getUtilisateur()->getEmailCanonical())
                ->setCharset('utf-8')
                ->setContentType('text/html')
                ->setBody($this->renderView('EcommerceBundle:Default:SwiftLayout/MailConfirmationCommande.html.twig', array('utilisateur' => $commande->getUtilisateur())));
        $this->get('mailer')->send($message);

        $this->get('session')->getFlashBag()->add('success', 'Votre commande a été validée avec succès');
        return $this->redirect($this->generateUrl('factures'));
    }

}
