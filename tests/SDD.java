package tests;

import java.time.Duration;
import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.Order;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.TestMethodOrder;
import org.junit.jupiter.api.MethodOrderer.OrderAnnotation;
import org.openqa.selenium.By;
import org.openqa.selenium.WebDriver;
import org.openqa.selenium.WebElement;
import org.openqa.selenium.NoSuchElementException;
import org.openqa.selenium.interactions.Actions;
import org.openqa.selenium.JavascriptExecutor;
import org.openqa.selenium.support.ui.ExpectedConditions;
import org.openqa.selenium.support.ui.WebDriverWait;

@TestMethodOrder(OrderAnnotation.class)
public class SDD extends BaseTest {

    @Test
    @Order(1)
    public void addNew() {
        try {
            authenticate();
            Thread.sleep(2000);

            WebDriverWait wait = new WebDriverWait(driver, Duration.ofSeconds(10));
            Actions actions = new Actions(driver);
            WebElement dropdownMenu = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage Study Elements' and contains(text(), 'Study Elements')]")));
            actions.moveToElement(dropdownMenu).click().perform();
            Thread.sleep(1000);

            WebElement subOption = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage SDD Templates' and text()='Manage SDD Templates']")));
            subOption.click();

            wait.until(ExpectedConditions.urlContains("/rep/select/mt/sdd/table/1/9/none"));
            WebElement addButton = wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-add-element")));
            addButton.click();

            wait.until(ExpectedConditions.urlContains("/rep/manage/addmt/sdd/none/F"));
            WebElement nameField = driver.findElement(By.id("edit-mt-name"));
            nameField.sendKeys(System.getProperty("card-name"));  // Usa o valor de -Dcard-name
            WebElement abbreviationField = driver.findElement(By.id("edit-mt-version"));
            abbreviationField.sendKeys("1");

            String filePath = System.getProperty("user.dir") + System.getProperty("file");  // Usa o valor de -Dfile
            WebElement uploadField = driver.findElement(By.id("edit-mt-filename-upload"));
            uploadField.sendKeys(filePath);
            Thread.sleep(5000);

            WebElement saveButton = driver.findElement(By.id("edit-save-submit"));
            ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", saveButton);
            saveButton.click();

            wait.until(ExpectedConditions.urlContains("/rep/select/mt/sdd/table/1/9/none"));
            WebElement sddTable = driver.findElement(By.id("edit-element-table"));
            WebElement addedSDDTemplate = sddTable.findElement(By.xpath("//td[contains(text(), '" + System.getProperty("card-name") + "')]"));

            Assertions.assertNotNull(addedSDDTemplate, "SDD Template File not found on List");
        } catch (Exception e) {
            Assertions.fail("Add New SDD Template failed: \n\n" + e.getMessage());
        }
    }

    @Test
    @Order(2)
    public void ingestSDD() {
        try {
            authenticate();
            Thread.sleep(2000);

            WebDriverWait wait = new WebDriverWait(driver, Duration.ofSeconds(10));
            Actions actions = new Actions(driver);
            WebElement dropdownMenu = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage Study Elements' and contains(text(), 'Study Elements')]")));
            actions.moveToElement(dropdownMenu).click().perform();
            Thread.sleep(1000);

            WebElement subOption = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage SDD Templates' and text()='Manage SDD Templates']")));
            subOption.click();

            // CHANGE VIEW TO CARD
            wait.until(ExpectedConditions.urlContains("/rep/select/mt/sdd/table/1/9/none"));
            WebElement addButton = wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-card-view")));
            addButton.click();

            // CHECK IF THERE IS A CARD WITH TITLE 
            WebElement sddCard = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//h5[contains(text(), '" + System.getProperty("card-name") + "')]/ancestor::div[contains(@class, 'card')]")));

            // CLICK "Ingest" BUTTON INSIDE CARD
            WebElement ingestButton = sddCard.findElement(By.xpath(".//button[@data-drupal-selector='edit-ingest']"));
            ((JavascriptExecutor) driver).executeScript("arguments[0].scrollIntoView(true);", ingestButton);
            ingestButton.click();
            Thread.sleep(2000);

            // VERIFICATION LOOP INSIDE CARD
            boolean isProcessed = false;
            for (int i = 0; i < 10; i++) {
                // REFRESH PAGE
                driver.navigate().refresh();

                // LOCATE CARD AFTER REFRESH
                sddCard = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//h5[contains(text(), '" + System.getProperty("card-name") + "')]/ancestor::div[contains(@class, 'card')]")));

                try {
                    // LOCATE ELEMENT INSIDE CARD
                    WebElement statusElement = sddCard.findElement(By.xpath(".//*[@data-drupal-selector='edit-element-status']//font"));
                    String statusText = statusElement.getText().trim();

                    // VERIFY IS STATUS IS "PROCESSED"
                    if (statusText.equals("PROCESSED")) {
                        isProcessed = true;
                        break;
                    }
                } catch (NoSuchElementException e) {
                    isProcessed = false;
                }

                // WAITH UNTIL NEW RETRY
                Thread.sleep(7000);
            }

            // FINAL VERIFICATION TO ASSURE STATUS IS "PROCESSED"
            Assertions.assertTrue(isProcessed, "Status did not reach 'PROCESSED' after X attempts");

        } catch (Exception e) {
            Assertions.fail("Ingest SDD Template failed: \n\n" + e.getMessage());
        }
    }

    @Test
    @Order(3)
    public void semanticDictionaryExists() {
        try {
            authenticate();
            Thread.sleep(2000);
    
            WebDriverWait wait = new WebDriverWait(driver, Duration.ofSeconds(10));
            Actions actions = new Actions(driver);
            WebElement dropdownMenu = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage Study Elements' and contains(text(), 'Study Elements')]")));
            actions.moveToElement(dropdownMenu).click().perform();
            Thread.sleep(1000);
    
            WebElement subOption = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//a[@title='Manage Semantic Data Dictionary' and text()='Manage Semantic Data Dictionary']")));
            subOption.click();

            // CHANGE VIEW TO CARD
            wait.until(ExpectedConditions.urlContains("sem/select/semanticdatadictionary/1/9"));
            WebElement addButton = wait.until(ExpectedConditions.visibilityOfElementLocated(By.id("edit-card-view")));
            addButton.click();
    
            // CHECK IF THERE IS A CARD WITH TITLE 
            wait.until(ExpectedConditions.urlContains("sem/select/semanticdatadictionary/1/9"));
            WebElement sddCard = wait.until(ExpectedConditions.visibilityOfElementLocated(By.xpath("//h5[contains(text(), '" + System.getProperty("sdd-title") + "')]/ancestor::div[contains(@class, 'card-header')]")));
    
            // FINAL VERIFICATION TO ASSURE CARD HAS BEEN FOUND
            Assertions.assertNotNull(sddCard, "Semantic Data Dictionary not found");
    
        } catch (Exception e) {
            Assertions.fail("Semantic Data Dictionary '" + System.getProperty("sdd-title") + "' not found: \n\n" + e.getMessage());
        }
    }
}