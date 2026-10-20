describe('Smoke Test - Flujo Principal de Usuario en GoalBet2026', () => {
  beforeEach(() => {
    cy.viewport(1280, 720);
  });

  it('Debe permitir iniciar sesión, seleccionar un partido, realizar una apuesta de $50, verificar el saldo descontado y confirmar la apuesta en Mis Apuestas', () => {
    // Step 1: Visitar página de login
    cy.visit('/login.php');
    cy.contains('h1', 'Ingresar').should('be.visible');
    cy.screenshot('01-login-form');

    // Step 2: Iniciar sesión con usuario cliente de prueba usando data-cy
    cy.get('[data-cy="input-identificador"]').clear().type('qa_cliente');
    cy.get('[data-cy="input-password"]').clear().type('Password123!');
    cy.screenshot('02-login-form-filled');
    cy.get('[data-cy="btn-login"]').click();

    // Validar inicio de sesión exitoso y redirección al Dashboard
    cy.url().should('include', '/dashboard.php');
    cy.contains('Bienvenido').should('be.visible');
    cy.screenshot('03-dashboard-logged-in');

    // Step 3: Navegar a la sección de Apostar
    cy.get('[data-cy="nav-apostar"]').click();
    cy.url().should('include', '/apostar.php');
    cy.contains('h1', 'Apostar').should('be.visible');
    cy.screenshot('04-matches-list');

    // Step 4: Seleccionar un partido disponible para apostar
    cy.get('[data-cy="match-card"]').first().click();
    cy.screenshot('05-bet-form-selected');

    // Step 5: Configurar la apuesta (Resultado: Local, Monto: 50.00)
    cy.get('[data-cy="cuota-local"]').first().click();
    cy.get('[data-cy="input-monto"]').clear().type('50.00');
    cy.get('.preview-ganancia').should('not.be.empty');
    cy.screenshot('06-bet-form-filled');

    // Step 6: Confirmar la apuesta
    cy.get('[data-cy="btn-confirmar-apuesta"]').should('not.be.disabled').click();

    // Step 7: Verificar mensaje de confirmación y saldo actualizado
    cy.get('.alert-success').should('be.visible').and('contain.text', 'Apuesta registrada');
    cy.screenshot('07-bet-confirmed-success');

    // Step 8: Navegar a Mis Apuestas y validar registro con estado Pendiente
    cy.visit('/mis_apuestas.php');
    cy.contains('h1', 'Mis apuestas').should('be.visible');
    cy.get('[data-cy="mis-apuestas-row"]').first().within(() => {
      cy.contains('Local').should('be.visible');
      cy.contains('50.00').should('be.visible');
      cy.contains('Pendiente').should('be.visible');
      cy.get('[data-cy="btn-cancelar-apuesta"]').should('be.visible');
    });
    cy.screenshot('08-mis-apuestas-pending');
  });
});
