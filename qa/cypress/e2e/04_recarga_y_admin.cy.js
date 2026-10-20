describe('Flujo de Recarga de Saldo y Aprobación de Administrador', () => {
  const timestamp = Date.now();
  const montoRecarga = '200.00';
  const comprobanteRecarga = `COMP-CYPRESS-${timestamp}`;
  let referenciaRecarga = '';

  beforeEach(() => {
    cy.viewport(1280, 720);
  });

  it('1. Cliente solicita una recarga de saldo desde /recargar.php', () => {
    cy.visit('/login.php');
    cy.get('[data-cy="input-identificador"]').clear().type('qa_cliente');
    cy.get('[data-cy="input-password"]').clear().type('Password123!');
    cy.get('[data-cy="btn-login"]').click();
    cy.url().should('include', '/dashboard.php');

    cy.visit('/recargar.php');
    cy.contains('h1', 'Recargar saldo').should('be.visible');

    cy.get('[data-cy="input-monto-recarga"]').clear().type(montoRecarga);
    cy.get('[data-cy="select-metodo-recarga"]').select('Transferencia bancaria');
    cy.get('[data-cy="input-comprobante-recarga"]').clear().type(comprobanteRecarga);
    cy.get('[data-cy="btn-enviar-recarga"]').click();

    cy.get('.alert-success')
      .should('be.visible')
      .and('contain.text', 'Solicitud de recarga enviada')
      .invoke('text')
      .then((texto) => {
        const match = texto.match(/\(([^)]+)\)/);
        expect(match, 'referencia en el mensaje de éxito').to.not.be.null;
        referenciaRecarga = match[1];
      });

    cy.visit('/logout.php');
  });

  it('2. Administrador ingresa a /admin/recargas.php y aprueba la solicitud', () => {
    cy.visit('/login.php');
    cy.get('[data-cy="input-identificador"]').clear().type('qa_admin');
    cy.get('[data-cy="input-password"]').clear().type('Password123!');
    cy.get('[data-cy="btn-login"]').click();
    cy.url().should('include', '/dashboard.php');

    cy.visit('/admin/recargas.php');
    cy.contains('h1', 'Solicitudes de recarga').should('be.visible');

    cy.get('[data-cy="card-recarga"]')
      .filter(`:contains("${comprobanteRecarga}")`)
      .first()
      .within(() => {
        cy.get('[data-cy="btn-aprobar-recarga"]').click();
      });

    cy.get('.alert-success')
      .should('be.visible')
      .and('contain.text', 'aprobada');

    cy.visit('/logout.php');
  });

  it('3. Cliente verifica que la recarga fue acreditada a su saldo', () => {
    cy.visit('/login.php');
    cy.get('[data-cy="input-identificador"]').clear().type('qa_cliente');
    cy.get('[data-cy="input-password"]').clear().type('Password123!');
    cy.get('[data-cy="btn-login"]').click();
    cy.url().should('include', '/dashboard.php');

    cy.visit('/recargar.php');
    cy.contains('tr', referenciaRecarga).should('be.visible').within(() => {
      cy.contains('aprobada', { matchCase: false }).should('be.visible');
    });
  });
});